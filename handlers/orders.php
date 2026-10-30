<?php

declare(strict_types=1);

/**
 * Pedidos feitos pelo site e o pagamento deles no Mercado Pago (fase 10 de
 * docs/plano-pagamento-online.md, no repositório do front).
 *
 * O cliente contrata (POST /orders), paga na página do MP e volta para
 * /pedido/{public_id}. Quem confirma o pagamento é o MP, por dois caminhos:
 * o webhook (POST /webhooks/mercadopago) e, na volta, o payment_id que o MP
 * põe na URL (GET /orders/{public_id}?payment_id=...). Nos dois, a API busca
 * o pagamento no MP e só confia no que vem de lá (apply_payment_to_order).
 *
 * Status: pending → paid → event_created (o super cria o evento, fase 12),
 * ou expired (24h sem pagar), canceled, refunded (estorno/chargeback).
 */

const ORDER_ITEM_TITLES = [
    'wedding' => 'Convite de casamento',
    'birthday' => 'Convite de aniversário',
    'graduation' => 'Convite de formatura',
    'other' => 'Convite de celebração',
];

/**
 * POST /orders
 * Body: {
 *   choice: { event_type, guest_tier_id, gifts, premium, extra_admins, coupon? },
 *   customer: { name, email, phone },
 *   event: { host_name, host_name_secondary?, subtitle?, event_date? (AAAA-MM-DD) },
 *   notes?, website? (campo isca: só robô preenche)
 * }
 * Recalcula o preço (o front nunca manda valor), grava o pedido e cria a
 * página de pagamento no MP. Responde com o pedido e o checkout_url.
 */
function handle_create_order(PDO $pdo, array $config): void
{
    if (!mp_enabled($config)) {
        json_error(503, 'O pagamento pelo site ainda não está disponível. Fale com a gente pelo WhatsApp.');
    }

    $body = json_body();
    if (trim((string) ($body['website'] ?? '')) !== '') {
        // Robô: finge que deu certo e não cria nada.
        json_response(201, ['order' => ['public_id' => bin2hex(random_bytes(16)), 'status' => 'pending'], 'checkout_url' => null]);
    }

    $customer = is_array($body['customer'] ?? null) ? $body['customer'] : [];
    $event = is_array($body['event'] ?? null) ? $body['event'] : [];
    $choiceBody = is_array($body['choice'] ?? null) ? $body['choice'] : [];

    $name = order_text($customer, 'name', 150, 'Informe seu nome.');
    $email = trim((string) ($customer['email'] ?? ''));
    if ($email === '' || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error(422, 'Informe um email válido.');
    }
    $phone = normalize_order_phone((string) ($customer['phone'] ?? ''));
    $hostName = order_text($event, 'host_name', 150, 'Informe o nome que vai aparecer no convite.');
    $hostNameSecondary = order_text($event, 'host_name_secondary', 150);
    $subtitle = order_text($event, 'subtitle', 150);
    $eventDate = read_order_event_date($event);
    $notes = order_text(['notes' => str_replace("\r\n", "\n", (string) ($body['notes'] ?? ''))], 'notes', 1000);

    // O pedido nunca recalcula contra um evento existente.
    $parsed = ['event_id' => null] + read_quote_choice($pdo, $choiceBody);
    $quote = compute_quote($pdo, $parsed);
    if ($quote['coupon_error'] !== null) {
        json_error(422, $quote['coupon_error'], 'coupon');
    }
    if ($quote['subtotal'] <= 0) {
        json_error(503, 'Os preços ainda não estão disponíveis. Fale com a gente pelo WhatsApp.');
    }
    // O MP não cobra R$ 0: cupom que zera o pedido é usado com a gente.
    if ($quote['total'] < 1) {
        json_error(422, 'Esse cupom precisa ser usado com a gente pelo WhatsApp.', 'coupon');
    }

    $publicId = bin2hex(random_bytes(16));
    $ttlHours = max(1, (int) ($config['mercadopago']['order_ttl_hours'] ?? 24));
    // A validade vale no relógio do banco (expires_at, comparado com NOW())
    // e, para o MP, no mesmo instante em UTC (ver mp_create_preference).
    $expiresAt = time() + $ttlHours * 3600;

    $pdo->prepare(
        'INSERT INTO orders
           (public_id, customer_name, customer_email, customer_phone, event_type, host_name,
            host_name_secondary, subtitle, event_date, customer_notes, price_breakdown, total,
            coupon_code, expires_at)
         VALUES
           (:public_id, :customer_name, :customer_email, :customer_phone, :event_type, :host_name,
            :host_name_secondary, :subtitle, :event_date, :customer_notes, :price_breakdown, :total,
            :coupon_code, DATE_ADD(NOW(), INTERVAL :ttl HOUR))'
    )->execute([
        'public_id' => $publicId,
        'customer_name' => $name,
        'customer_email' => $email,
        'customer_phone' => $phone,
        'event_type' => $parsed['event_type'],
        'host_name' => $hostName,
        'host_name_secondary' => $hostNameSecondary,
        'subtitle' => $subtitle,
        'event_date' => $eventDate,
        'customer_notes' => $notes,
        'price_breakdown' => json_encode(quote_breakdown($parsed, $quote), JSON_UNESCAPED_UNICODE),
        'total' => $quote['total'],
        'coupon_code' => $quote['coupon']['code'] ?? null,
        'ttl' => $ttlHours,
    ]);
    $order = fetch_order($pdo, $publicId);

    $title = 'Presenzo · ' . ORDER_ITEM_TITLES[$order['event_type']];
    $preference = mp_create_preference($config, $order, $title, $expiresAt);
    if ($preference === null) {
        // Nunca chegou ao MP: não vale como pedido.
        $pdo->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $order['id']]);
        json_error(502, 'Não foi possível abrir o pagamento agora. Tente de novo em instantes.');
    }

    $pdo->prepare('UPDATE orders SET mp_preference_id = :preference_id, checkout_url = :checkout_url WHERE id = :id')
        ->execute(['preference_id' => $preference['id'], 'checkout_url' => $preference['init_point'], 'id' => $order['id']]);
    $order = fetch_order($pdo, $publicId);

    json_response(201, ['order' => format_public_order($order), 'checkout_url' => $order['checkout_url']]);
}

/**
 * GET /orders/{public_id}[?payment_id=...]
 * Status do pedido para a página /pedido/{public_id}. O public_id é o
 * segredo da página (não há login), então a resposta nunca traz email nem
 * telefone. Com payment_id (o MP põe na URL de volta), a API confere esse
 * pagamento no MP na hora: não depende do webhook ter chegado.
 */
function handle_get_order(PDO $pdo, array $config, string $publicId): void
{
    $order = fetch_order($pdo, $publicId);
    if (!$order) {
        json_error(404, 'Pedido não encontrado.');
    }

    $paymentId = trim((string) ($_GET['payment_id'] ?? ''));
    if ($paymentId !== '' && in_array($order['status'], ['pending', 'expired'], true) && mp_enabled($config)) {
        [$status, $payment] = mp_fetch_payment($config, $paymentId);
        if ($status === 200) {
            apply_payment_to_order($pdo, $config, $payment, 'return');
        }
    }

    expire_due_orders($pdo);
    json_response(200, ['order' => format_public_order(fetch_order($pdo, $publicId))]);
}

/**
 * POST /webhooks/mercadopago?data.id=...&type=payment
 * Aviso do MP. Só olha pagamentos: confere a assinatura (com webhook_secret
 * configurado), busca o pagamento no MP e aplica no pedido. Responde 200
 * rápido; 5xx faz o MP tentar de novo (a cada 15 min), o que é o que se quer
 * quando a consulta ao MP falha.
 */
function handle_mercadopago_webhook(PDO $pdo, array $config): void
{
    $raw = file_get_contents('php://input');
    $body = is_string($raw) ? json_decode($raw, true) : null;
    $body = is_array($body) ? $body : [];
    $query = raw_query_params();

    $type = (string) ($query['type'] ?? $body['type'] ?? '');
    $dataId = (string) ($query['data.id'] ?? ($body['data']['id'] ?? ''));
    if ($type !== 'payment' || $dataId === '') {
        json_response(200, ['ok' => true, 'ignored' => true]);
    }

    $secret = trim((string) ($config['mercadopago']['webhook_secret'] ?? ''));
    if ($secret !== '') {
        $valid = mp_signature_valid(
            $secret,
            (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''),
            (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''),
            (string) ($query['data.id'] ?? $dataId)
        );
        if (!$valid) {
            error_log("MercadoPago: webhook com assinatura inválida (data.id {$dataId}).");
            json_error(401, 'Assinatura inválida.');
        }
    }

    if (!mp_enabled($config)) {
        json_error(503, 'Pagamento online desligado.');
    }

    [$status, $payment] = mp_fetch_payment($config, $dataId);
    if ($status === 404) {
        log_payment_notification($pdo, null, $dataId, null, 'payment_not_found', ['query' => $query, 'body' => $body]);
        json_response(200, ['ok' => true, 'ignored' => true]);
    }
    if ($status !== 200) {
        json_error(502, 'Não foi possível consultar o pagamento.');
    }

    $outcome = apply_payment_to_order($pdo, $config, $payment, 'webhook', ['query' => $query, 'body' => $body]);
    json_response(200, ['ok' => true, 'outcome' => $outcome]);
}

/**
 * Aplica no pedido o que o MP diz do pagamento e grava no diário
 * (payment_notifications). Idempotente: cada mudança filtra pelo status de
 * origem, então aviso repetido ou fora de ordem não faz nada duas vezes, e
 * os emails só saem na mudança de verdade.
 *
 * - approved, com o valor e a moeda do pedido: pending/expired → paid;
 * - approved com outro valor: não marca, e avisa (amount_mismatch);
 * - approved num pedido cancelado: não marca, e avisa (paid_while_canceled);
 * - refunded/charged_back do pagamento do pedido: paid/event_created → refunded;
 * - o resto (pendente, em análise, recusado): só registra. Recusado deixa o
 *   pedido pendente, e a pessoa pode tentar de novo na mesma página do MP.
 *
 * @return string o que aconteceu (outcome do diário)
 */
function apply_payment_to_order(PDO $pdo, array $config, array $payment, string $source, array $notification = []): string
{
    $paymentId = (string) ($payment['id'] ?? '');
    $mpStatus = (string) ($payment['status'] ?? '');
    $publicId = (string) ($payment['external_reference'] ?? '');
    $order = preg_match('/^[a-f0-9]{32}$/', $publicId) ? fetch_order($pdo, $publicId) : null;

    if (!$order) {
        $outcome = 'order_not_found';
    } elseif ($mpStatus === 'approved') {
        $outcome = apply_approved_payment($pdo, $order, $payment);
    } elseif (in_array($mpStatus, ['refunded', 'charged_back'], true)) {
        $stmt = $pdo->prepare(
            "UPDATE orders SET status = 'refunded'
             WHERE id = :id AND status IN ('paid', 'event_created') AND mp_payment_id = :payment_id"
        );
        $stmt->execute(['id' => $order['id'], 'payment_id' => $paymentId]);
        $outcome = $stmt->rowCount() === 1 ? 'refunded' : 'ignored';
    } else {
        $outcome = 'ignored';
    }

    log_payment_notification($pdo, $order ? (int) $order['id'] : null, $paymentId, $mpStatus, $outcome, [
        'source' => $source,
        'notification' => $notification,
        'payment' => array_intersect_key($payment, array_flip([
            'id', 'status', 'status_detail', 'external_reference', 'transaction_amount', 'currency_id',
            'payment_method_id', 'payment_type_id', 'installments', 'date_created', 'date_approved', 'live_mode',
        ])) + ['total_paid_amount' => $payment['transaction_details']['total_paid_amount'] ?? null],
    ]);

    if ($order && $outcome !== 'ignored') {
        notify_order_outcome($pdo, $config, $order['public_id'], $outcome, $payment);
    }

    return $outcome;
}

function apply_approved_payment(PDO $pdo, array $order, array $payment): string
{
    $amount = (float) ($payment['transaction_amount'] ?? 0);
    if (($payment['currency_id'] ?? '') !== 'BRL' || abs($amount - (float) $order['total']) > 0.009) {
        return $order['status'] === 'paid' ? 'ignored' : 'amount_mismatch';
    }
    if ($order['status'] === 'canceled') {
        return 'paid_while_canceled';
    }

    $method = ($payment['payment_method_id'] ?? '') === 'pix' ? 'pix' : (string) ($payment['payment_type_id'] ?? '');
    $stmt = $pdo->prepare(
        "UPDATE orders
         SET status = 'paid', mp_payment_id = :payment_id, payment_method = :method,
             installments = :installments, paid_amount = :amount, paid_at = NOW()
         WHERE id = :id AND status IN ('pending', 'expired')"
    );
    $stmt->execute([
        'payment_id' => (string) $payment['id'],
        'method' => $method !== '' ? substr($method, 0, 30) : null,
        'installments' => isset($payment['installments']) ? (int) $payment['installments'] : null,
        'amount' => $amount,
        'id' => $order['id'],
    ]);

    return $stmt->rowCount() === 1 ? 'paid' : 'ignored';
}

/**
 * Emails depois de um aviso que mudou algo (ou que precisa de olho humano):
 * o cliente recebe a confirmação do pagamento, e app.notify_email recebe o
 * aviso de pedido pago, estornado ou com problema.
 */
function notify_order_outcome(PDO $pdo, array $config, string $publicId, string $outcome, array $payment): void
{
    $order = fetch_order($pdo, $publicId);
    $frontendUrl = rtrim((string) ($config['app']['frontend_url'] ?? ''), '/');
    $eventLabel = format_event_label(
        $order['event_type'],
        $order['host_name'],
        $order['host_name_secondary'],
        $order['subtitle']
    );

    if ($outcome === 'paid') {
        $breakdown = json_decode((string) $order['price_breakdown'], true) ?: [];
        $message = build_order_paid_email(
            $order['customer_name'],
            $eventLabel,
            $breakdown['items'] ?? [],
            (float) $order['total'],
            "{$frontendUrl}/pedido/{$order['public_id']}"
        );
        send_email($config, $order['customer_email'], $message['subject'], $message['html']);
    }

    $notifyTo = trim((string) ($config['app']['notify_email'] ?? ''));
    $notices = [
        'paid' => ["Novo pedido pago: {$eventLabel}", 'Pedido pago pelo site. Crie o evento no super.'],
        'refunded' => [
            "Pedido estornado: {$eventLabel}",
            'O pagamento deste pedido foi estornado ou contestado no Mercado Pago. Se o evento já existe, ele continua no ar: decida o que fazer.',
        ],
        'amount_mismatch' => [
            "Pagamento com valor diferente: {$eventLabel}",
            'O Mercado Pago aprovou um pagamento com valor diferente do pedido. O pedido não foi marcado como pago.',
        ],
        'paid_while_canceled' => [
            "Pagamento em pedido cancelado: {$eventLabel}",
            'Chegou um pagamento aprovado num pedido cancelado. Estorne pelo painel do Mercado Pago ou fale com o cliente.',
        ],
    ];
    if ($notifyTo === '' || !isset($notices[$outcome])) {
        return;
    }

    [$subject, $headline] = $notices[$outcome];
    $message = build_order_notice_email($subject, $headline, [
        'Cliente' => $order['customer_name'],
        'Email' => $order['customer_email'],
        'WhatsApp' => "https://wa.me/{$order['customer_phone']}",
        'Evento' => $eventLabel,
        'Data' => $order['event_date'] !== null ? date('d/m/Y', strtotime($order['event_date'])) : 'ainda não sabe',
        'Total do pedido' => format_brl((float) $order['total']),
        'Pagamento' => order_payment_label($payment) . ' · ' . format_brl((float) ($payment['transaction_amount'] ?? 0)),
        'Cupom' => $order['coupon_code'] ?? 'nenhum',
    ], "{$frontendUrl}/super/pedidos/{$order['id']}");
    send_email($config, $notifyTo, $message['subject'], $message['html']);
}

/** "Pix", "Cartão de crédito em 3x", "Cartão de débito"… */
function order_payment_label(array $payment): string
{
    if (($payment['payment_method_id'] ?? '') === 'pix') {
        return 'Pix';
    }
    $type = (string) ($payment['payment_type_id'] ?? '');
    if ($type === 'credit_card') {
        $installments = (int) ($payment['installments'] ?? 1);
        return $installments > 1 ? "Cartão de crédito em {$installments}x" : 'Cartão de crédito à vista';
    }
    $labels = ['debit_card' => 'Cartão de débito', 'account_money' => 'Saldo do Mercado Pago'];

    return $labels[$type] ?? ($type !== '' ? $type : 'não informado');
}

function log_payment_notification(PDO $pdo, ?int $orderId, ?string $paymentId, ?string $mpStatus, string $outcome, array $payload): void
{
    $pdo->prepare(
        'INSERT INTO payment_notifications (order_id, mp_payment_id, mp_status, outcome, payload)
         VALUES (:order_id, :payment_id, :mp_status, :outcome, :payload)'
    )->execute([
        'order_id' => $orderId,
        'payment_id' => $paymentId !== null && $paymentId !== '' ? substr($paymentId, 0, 32) : null,
        'mp_status' => $mpStatus !== null && $mpStatus !== '' ? substr($mpStatus, 0, 30) : null,
        'outcome' => $outcome,
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

/** Pedido pelo public_id, com quantos segundos faltam para expirar (no relógio do banco). */
function fetch_order(PDO $pdo, string $publicId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT *, TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS expires_in_seconds
         FROM orders WHERE public_id = :public_id'
    );
    $stmt->execute(['public_id' => $publicId]);
    $order = $stmt->fetch();

    return $order ?: null;
}

/** Pendentes que passaram do prazo viram expired. */
function expire_due_orders(PDO $pdo): void
{
    $pdo->exec("UPDATE orders SET status = 'expired' WHERE status = 'pending' AND expires_at <= NOW()");
}

/**
 * O pedido para a página pública /pedido/{public_id}: sem email nem telefone
 * (o link não pede login), só o primeiro nome para cumprimentar.
 */
function format_public_order(array $order): array
{
    $breakdown = json_decode((string) $order['price_breakdown'], true) ?: [];
    $pending = $order['status'] === 'pending';

    return [
        'public_id' => $order['public_id'],
        'status' => $order['status'],
        'customer_first_name' => explode(' ', trim($order['customer_name']))[0],
        'event_type' => $order['event_type'],
        'host_name' => $order['host_name'],
        'host_name_secondary' => $order['host_name_secondary'],
        'subtitle' => $order['subtitle'],
        'event_date' => $order['event_date'],
        'items' => $breakdown['items'] ?? [],
        'total' => (float) $order['total'],
        'coupon_code' => $order['coupon_code'],
        'payment_method' => $order['payment_method'],
        'installments' => $order['installments'] !== null ? (int) $order['installments'] : null,
        'checkout_url' => $pending ? $order['checkout_url'] : null,
        'expires_in_seconds' => $pending ? max(0, (int) $order['expires_in_seconds']) : null,
    ];
}

/**
 * Texto do formulário de pedido: aparado, com limite, e com a mensagem de
 * "obrigatório" do próprio formulário ($requiredMessage null = opcional).
 */
function order_text(array $data, string $key, int $maxLength, ?string $requiredMessage = null): ?string
{
    $value = trim((string) ($data[$key] ?? ''));
    if ($value === '') {
        if ($requiredMessage !== null) {
            json_error(422, $requiredMessage);
        }
        return null;
    }
    if (mb_strlen($value) > $maxLength) {
        json_error(422, "Texto muito longo (máximo de {$maxLength} caracteres).");
    }

    return $value;
}

/** WhatsApp com DDD, só dígitos e com o 55 do Brasil: "(77) 99999-0000" → "5577999990000". */
function normalize_order_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 10 || strlen($digits) === 11) {
        $digits = '55' . $digits;
    }
    if (!preg_match('/^55\d{10,11}$/', $digits)) {
        json_error(422, 'Informe um WhatsApp com DDD.');
    }

    return $digits;
}

/** Data do evento (opcional, "ainda não sei"): AAAA-MM-DD, de ontem em diante. */
function read_order_event_date(array $event): ?string
{
    $raw = trim((string) ($event['event_date'] ?? ''));
    if ($raw === '') {
        return null;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $raw);
    if (!$date || $date->format('Y-m-d') !== $raw) {
        json_error(422, 'Data do evento inválida.');
    }
    // Um dia de folga: o PHP roda em UTC, e à noite no Brasil já é amanhã lá.
    if ($raw < date('Y-m-d', strtotime('-1 day'))) {
        json_error(422, 'A data do evento já passou.');
    }

    return $raw;
}

/**
 * Parâmetros da URL sem a troca de "." por "_" que o PHP faz no $_GET: o
 * MP manda "data.id", e a assinatura usa esse valor exatamente como veio.
 */
function raw_query_params(): array
{
    $params = [];
    foreach (explode('&', (string) ($_SERVER['QUERY_STRING'] ?? '')) as $pair) {
        if ($pair === '') {
            continue;
        }
        [$key, $value] = explode('=', $pair, 2) + [1 => ''];
        $params[urldecode($key)] = urldecode($value);
    }

    return $params;
}
