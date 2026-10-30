<?php

declare(strict_types=1);

/**
 * Pedidos feitos pelo site, no super-admin (fase 12 de
 * docs/plano-pagamento-online.md, no front): a lista, o detalhe com o
 * diário de avisos do Mercado Pago, notas, cancelar e "Conferir no Mercado
 * Pago". O "Criar evento" é o POST /admin/super/events com order_id (ver
 * handle_create_event em handlers/super_events.php).
 */

const SUPER_ORDER_COLUMNS = 'id, public_id, status, customer_name, customer_email, customer_phone, event_type, host_name,
    host_name_secondary, subtitle, event_date, customer_notes, price_breakdown, total, coupon_code, mp_payment_id,
    payment_method, installments, paid_amount, paid_at, expires_at, event_id, super_notes, created_at';

/**
 * GET /admin/super/orders
 * Todos os pedidos, do mais novo para o mais antigo (são poucos; a tela
 * filtra), e quantos há em cada status.
 */
function handle_list_orders(PDO $pdo): void
{
    expire_due_orders($pdo);

    $orders = $pdo->query('SELECT ' . SUPER_ORDER_COLUMNS . ' FROM orders ORDER BY created_at DESC, id DESC')->fetchAll();
    $counts = array_fill_keys(['pending', 'paid', 'event_created', 'expired', 'canceled', 'refunded'], 0);
    foreach ($orders as $order) {
        $counts[$order['status']]++;
    }

    json_response(200, ['orders' => array_map('format_super_order', $orders), 'counts' => $counts]);
}

/**
 * GET /admin/super/orders/{id}
 * O pedido com o orçamento, as notas e o diário de avisos do Mercado Pago.
 */
function handle_get_super_order(PDO $pdo, int $id): void
{
    expire_due_orders($pdo);
    json_response(200, ['order' => super_order_detail($pdo, fetch_super_order($pdo, $id))]);
}

/**
 * PUT /admin/super/orders/{id}
 * Body: { super_notes?, status?: "canceled" }. Cancelar vale para pedido
 * aguardando, expirado ou pago (o estorno em si é feito no painel do MP, e o
 * aviso de estorno chega pelo webhook). Pedido que já virou evento não cancela.
 */
function handle_update_super_order(PDO $pdo, int $id): void
{
    $order = fetch_super_order($pdo, $id);
    $body = json_body();

    if (array_key_exists('super_notes', $body)) {
        $notes = trim(str_replace("\r\n", "\n", (string) $body['super_notes']));
        if (mb_strlen($notes) > 2000) {
            json_error(422, 'Notas muito longas (máximo de 2000 caracteres).');
        }
        $pdo->prepare('UPDATE orders SET super_notes = :notes WHERE id = :id')
            ->execute(['notes' => $notes !== '' ? $notes : null, 'id' => $id]);
    }

    if (array_key_exists('status', $body)) {
        if ($body['status'] !== 'canceled') {
            json_error(422, 'O único status que dá pra mudar aqui é cancelar.');
        }
        $stmt = $pdo->prepare(
            "UPDATE orders SET status = 'canceled' WHERE id = :id AND status IN ('pending', 'expired', 'paid')"
        );
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0 && $order['status'] !== 'canceled') {
            json_error(409, 'Este pedido não pode ser cancelado (já virou evento ou foi estornado).');
        }
    }

    json_response(200, ['order' => super_order_detail($pdo, fetch_super_order($pdo, $id))]);
}

/**
 * POST /admin/super/orders/{id}/check
 * "Conferir no Mercado Pago": busca os pagamentos do pedido no MP e aplica
 * como o webhook faria (ver reconcile_order_with_mp). Responde o pedido
 * atualizado e quantos pagamentos o MP tem para ele.
 */
function handle_check_super_order(PDO $pdo, array $config, int $id): void
{
    $order = fetch_super_order($pdo, $id);
    if (!mp_enabled($config)) {
        json_error(503, 'Pagamento online desligado (sem access_token do Mercado Pago).');
    }

    $found = reconcile_order_with_mp($pdo, $config, $order, 'super');
    if ($found < 0) {
        json_error(502, 'Não foi possível consultar o Mercado Pago agora. Tente de novo em instantes.');
    }

    json_response(200, ['order' => super_order_detail($pdo, fetch_super_order($pdo, $id)), 'payments_found' => $found]);
}

function fetch_super_order(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT ' . SUPER_ORDER_COLUMNS . ' FROM orders WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $order = $stmt->fetch();
    if (!$order) {
        json_error(404, 'Pedido não encontrado.');
    }

    return $order;
}

function format_super_order(array $order): array
{
    $breakdown = json_decode((string) $order['price_breakdown'], true) ?: [];

    return [
        'id' => (int) $order['id'],
        'public_id' => $order['public_id'],
        'status' => $order['status'],
        'customer_name' => $order['customer_name'],
        'customer_email' => $order['customer_email'],
        'customer_phone' => $order['customer_phone'],
        'event_type' => $order['event_type'],
        'host_name' => $order['host_name'],
        'host_name_secondary' => $order['host_name_secondary'],
        'subtitle' => $order['subtitle'],
        'event_date' => $order['event_date'],
        'total' => (float) $order['total'],
        'coupon_code' => $order['coupon_code'],
        'choice' => $breakdown['choice'] ?? null,
        'payment_method' => $order['payment_method'],
        'installments' => $order['installments'] !== null ? (int) $order['installments'] : null,
        'paid_amount' => $order['paid_amount'] !== null ? (float) $order['paid_amount'] : null,
        'paid_at' => $order['paid_at'],
        'expires_at' => $order['expires_at'],
        'event_id' => $order['event_id'] !== null ? (int) $order['event_id'] : null,
        'created_at' => $order['created_at'],
    ];
}

/** Detalhe: a linha da lista mais orçamento, notas e o diário de avisos. */
function super_order_detail(PDO $pdo, array $order): array
{
    $breakdown = json_decode((string) $order['price_breakdown'], true) ?: [];

    $stmt = $pdo->prepare(
        'SELECT id, mp_payment_id, mp_status, outcome, payload, created_at
         FROM payment_notifications WHERE order_id = :order_id ORDER BY id DESC'
    );
    $stmt->execute(['order_id' => $order['id']]);
    $notifications = array_map(static function (array $row): array {
        $payload = json_decode((string) $row['payload'], true) ?: [];
        return [
            'id' => (int) $row['id'],
            'mp_payment_id' => $row['mp_payment_id'],
            'mp_status' => $row['mp_status'],
            'outcome' => $row['outcome'],
            'source' => $payload['source'] ?? null,
            'amount' => isset($payload['payment']['transaction_amount']) ? (float) $payload['payment']['transaction_amount'] : null,
            'created_at' => $row['created_at'],
        ];
    }, $stmt->fetchAll());

    return format_super_order($order) + [
        'items' => $breakdown['items'] ?? [],
        'customer_notes' => $order['customer_notes'],
        'super_notes' => $order['super_notes'],
        'mp_payment_id' => $order['mp_payment_id'],
        'notifications' => $notifications,
    ];
}
