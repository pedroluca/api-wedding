<?php

declare(strict_types=1);

/**
 * GET /admin/super/events
 */
function handle_list_events(PDO $pdo): void
{
    $stmt = $pdo->query(
        'SELECT id, slug, event_type, host_name, host_name_secondary, subtitle, event_date,
                venue_name, venue_name_secondary, address, maps_url, maps_url_secondary, dress_code,
                invite_message, share_message, pix_key, logo_path, cover_photo_path, cover_focus_x,
                cover_focus_y, music_path, color_primary, name_font, invite_style, show_countdown,
                show_calendar, plan, gifts_enabled, guest_limit, admin_limit, is_demo,
                access_expires_at, price_charged, coupon_code, price_breakdown, last_payment_at,
                payment_notes, created_at
         FROM events
         ORDER BY created_at DESC'
    );

    $stats = fetch_event_stats($pdo);
    json_response(200, ['events' => array_map(
        static fn (array $event): array => format_super_event($event) + [
            'stats' => $stats[(int) $event['id']] ?? empty_event_stats(),
        ],
        $stmt->fetchAll()
    )]);
}

/**
 * GET /admin/super/events/{id}
 */
function handle_get_event_detail(PDO $pdo, int $id): void
{
    $event = fetch_event_row($pdo, $id);
    if (!$event) {
        json_error(404, 'Evento não encontrado.');
    }

    $stats = fetch_event_stats($pdo, $id);
    json_response(200, ['event' => format_super_event($event) + [
        'stats' => $stats[$id] ?? empty_event_stats(),
    ]]);
}

/**
 * POST /admin/super/events/{id}/impersonate
 * "Entrar como admin": abre uma sessão curta no painel do evento, como o
 * primeiro admin dele, já com este evento ativo e marcada com o super-admin
 * que entrou (ver admin_sessions.impersonated_by). Mesmo formato de
 * resposta do login.
 */
function handle_impersonate_event_admin(PDO $pdo, array $superAdmin, int $id): void
{
    if (!fetch_event_row($pdo, $id)) {
        json_error(404, 'Evento não encontrado.');
    }

    $stmt = $pdo->prepare(
        'SELECT au.id, au.name, au.email, au.is_super
         FROM event_admins ea
         INNER JOIN admin_users au ON au.id = ea.admin_user_id
         WHERE ea.event_id = :event_id
         ORDER BY ea.created_at ASC, au.id ASC
         LIMIT 1'
    );
    $stmt->execute(['event_id' => $id]);
    $admin = $stmt->fetch();
    if (!$admin) {
        json_error(409, 'Este evento ainda não tem admin. Convide um na aba Admins.');
    }

    $token = generate_token();
    $pdo->prepare(
        'INSERT INTO admin_sessions (admin_user_id, event_id, impersonated_by, token, expires_at)
         VALUES (:admin_user_id, :event_id, :impersonated_by, :token, DATE_ADD(NOW(), INTERVAL 2 HOUR))'
    )->execute([
        'admin_user_id' => $admin['id'],
        'event_id' => $id,
        'impersonated_by' => $superAdmin['id'],
        'token' => $token,
    ]);

    $events = fetch_account_events($pdo, (int) $admin['id']);
    json_response(200, ['token' => $token] + account_session_payload($admin, $id, $events));
}

/**
 * Números de cada evento para o painel do super-admin (todos, ou só um com
 * $eventId): convites (titulares), pessoas (titulares + relacionados) por
 * status, admins e presentes escolhidos. Agregado por evento em poucas
 * consultas, em vez de uma por evento.
 *
 * @return array<int, array<string, int>> event_id => números
 */
function fetch_event_stats(PDO $pdo, ?int $eventId = null): array
{
    $where = $eventId !== null ? 'WHERE event_id = :event_id' : '';
    $params = $eventId !== null ? ['event_id' => $eventId] : [];
    $stats = [];

    $guests = $pdo->prepare(
        "SELECT event_id,
                SUM(related_to_id IS NULL) AS invites,
                COUNT(*) AS people,
                SUM(status = 'confirmado') AS confirmed,
                SUM(status = 'pendente') AS pending,
                SUM(status = 'recusado') AS declined
         FROM guests {$where}
         GROUP BY event_id"
    );
    $guests->execute($params);
    foreach ($guests->fetchAll() as $row) {
        $stats[(int) $row['event_id']] = [
            'invites' => (int) $row['invites'],
            'people' => (int) $row['people'],
            'confirmed' => (int) $row['confirmed'],
            'pending' => (int) $row['pending'],
            'declined' => (int) $row['declined'],
        ] + empty_event_stats();
    }

    $admins = $pdo->prepare("SELECT event_id, COUNT(*) AS n FROM event_admins {$where} GROUP BY event_id");
    $admins->execute($params);
    foreach ($admins->fetchAll() as $row) {
        $stats[(int) $row['event_id']] = ['admins' => (int) $row['n']] + ($stats[(int) $row['event_id']] ?? empty_event_stats());
    }

    $claims = $pdo->prepare(
        'SELECT gi.event_id, COUNT(*) AS n
         FROM gift_claims gc
         INNER JOIN gifts gi ON gi.id = gc.gift_id
         ' . ($eventId !== null ? 'WHERE gi.event_id = :event_id' : '') . '
         GROUP BY gi.event_id'
    );
    $claims->execute($params);
    foreach ($claims->fetchAll() as $row) {
        $stats[(int) $row['event_id']] = ['gift_claims' => (int) $row['n']] + ($stats[(int) $row['event_id']] ?? empty_event_stats());
    }

    return $stats;
}

function empty_event_stats(): array
{
    return ['invites' => 0, 'people' => 0, 'confirmed' => 0, 'pending' => 0, 'declined' => 0, 'admins' => 0, 'gift_claims' => 0];
}

/**
 * POST /admin/super/events
 * multipart/form-data: campos de marca (ver read_event_branding_fields),
 * event_type, plano e contratado (read_sent_plan_fields), orçamento aplicado
 * (quote, ver read_sent_quote) e valor cobrado, logo opcional, e
 * admin_name/admin_email do primeiro admin do evento. Cria o evento (com
 * slug gerado a partir dos nomes), o primeiro admin (senha inutilizável) e
 * dispara o email de convite. Se o email já tem conta, o evento só entra na
 * lista dela e o email avisa do evento novo (sem senha nova).
 *
 * order_id (opcional): o evento nasce de um pedido pago pelo site. O que foi
 * vendido vem do pedido, não do formulário: orçamento, cupom, valor cobrado
 * (o pago) e a data do pagamento (entra no "Recebido no mês"). Na mesma
 * transação o pedido vira event_created; pedido que não está pago → 409.
 */
function handle_create_event(PDO $pdo, array $config): void
{
    $eventType = read_event_type($_POST);
    $fields = read_event_branding_fields($_POST) + read_sent_branding_fields($_POST) + [
        'subtitle' => null,
        'invite_style' => default_invite_style($eventType),
        'show_countdown' => 1,
        'show_calendar' => 1,
    ];
    $planFields = read_sent_plan_fields($_POST) + [
        'plan' => 'standard',
        'gifts_enabled' => 1,
        'guest_limit' => null,
        'admin_limit' => null,
    ];

    $quoteFields = read_sent_quote($pdo, $_POST) + ['price_breakdown' => null, 'coupon_code' => null];
    $priceCharged = optional_decimal($_POST, 'price_charged');

    $adminName = require_string($_POST, 'admin_name', 150);
    $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
    if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        json_error(422, 'Informe um email válido para o admin do evento.');
    }

    $existingAccount = find_account_by_email($pdo, $adminEmail);
    if ($existingAccount && $existingAccount['is_super']) {
        json_error(409, 'Este email é de um super-admin. Use outro email para o admin do evento.');
    }

    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $orderId = $orderId !== false ? $orderId : null;
    $order = null;
    $lastPaymentAt = null;
    $paymentNotes = null;

    $logoPath = has_uploaded_file('logo') ? save_uploaded_image($_FILES['logo'], 'logos') : null;
    $accessExpiresAt = compute_access_expires_at($_POST, $fields['event_date'], $planFields['plan']);

    $pdo->beginTransaction();
    try {
        if ($orderId !== null) {
            // FOR UPDATE: dois cliques em "Criar evento" não criam dois eventos.
            $orderStmt = $pdo->prepare(
                'SELECT id, status, price_breakdown, coupon_code, paid_amount, paid_at, mp_payment_id,
                        payment_method, installments
                 FROM orders WHERE id = :id FOR UPDATE'
            );
            $orderStmt->execute(['id' => $orderId]);
            $order = $orderStmt->fetch() ?: null;
            if (!$order || $order['status'] !== 'paid') {
                $pdo->rollBack();
                json_error(409, 'Este pedido não está pago ou já virou evento.');
            }
            $quoteFields = ['price_breakdown' => $order['price_breakdown'], 'coupon_code' => $order['coupon_code']];
            $priceCharged = (float) $order['paid_amount'];
            $lastPaymentAt = substr((string) $order['paid_at'], 0, 10);
            $method = order_payment_label([
                'payment_method_id' => $order['payment_method'] === 'pix' ? 'pix' : '',
                'payment_type_id' => $order['payment_method'],
                'installments' => $order['installments'],
            ]);
            $paymentNotes = "Pago pelo site: {$method}, Mercado Pago {$order['mp_payment_id']}.";
        }

        $slug = generate_event_slug($pdo, $fields['host_name'], $fields['host_name_secondary']);

        $insert = $pdo->prepare(
            'INSERT INTO events
               (slug, event_type, host_name, host_name_secondary, subtitle, event_date, venue_name,
                venue_name_secondary, address, maps_url, maps_url_secondary, dress_code,
                invite_message, pix_key, logo_path, color_primary, name_font, invite_style,
                show_countdown, show_calendar, plan, gifts_enabled, guest_limit, admin_limit,
                access_expires_at, price_charged, price_breakdown, coupon_code, last_payment_at, payment_notes)
             VALUES
               (:slug, :event_type, :host_name, :host_name_secondary, :subtitle, :event_date, :venue_name,
                :venue_name_secondary, :address, :maps_url, :maps_url_secondary, :dress_code,
                :invite_message, :pix_key, :logo_path, :color_primary, :name_font, :invite_style,
                :show_countdown, :show_calendar, :plan, :gifts_enabled, :guest_limit, :admin_limit,
                :access_expires_at, :price_charged, :price_breakdown, :coupon_code, :last_payment_at, :payment_notes)'
        );
        $insert->execute($fields + $planFields + $quoteFields + [
            'price_charged' => $priceCharged,
            'last_payment_at' => $lastPaymentAt,
            'payment_notes' => $paymentNotes,
            'slug' => $slug,
            'event_type' => $eventType,
            'logo_path' => $logoPath,
            'access_expires_at' => $accessExpiresAt,
        ]);
        $eventId = (int) $pdo->lastInsertId();

        if ($existingAccount) {
            $adminId = (int) $existingAccount['id'];
            add_event_membership($pdo, $eventId, $adminId);
        } else {
            $adminId = create_event_admin($pdo, $eventId, $adminName, $adminEmail);
        }

        if ($order !== null) {
            $pdo->prepare("UPDATE orders SET status = 'event_created', event_id = :event_id WHERE id = :id AND status = 'paid'")
                ->execute(['event_id' => $eventId, 'id' => $order['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_error(500, 'Não foi possível criar o evento.');
    }

    // Fora da transação: envio de email é I/O externo e, se falhar, o admin
    // recém-criado ainda pode recuperar acesso depois via "esqueci minha senha".
    $eventLabel = format_event_label(
        $eventType,
        $fields['host_name'],
        $fields['host_name_secondary'],
        $fields['subtitle']
    );
    if ($existingAccount) {
        send_event_access_email($config, $existingAccount['name'], $existingAccount['email'], $eventLabel);
    } else {
        issue_and_send_password_token($pdo, $config, $adminId, $adminName, $adminEmail, 'invite', $eventLabel);
    }

    json_response(201, ['event' => format_super_event(fetch_event_row($pdo, $eventId))]);
}

/**
 * PUT /admin/super/events/{id}
 * JSON (não multipart — PHP só popula $_FILES em POST, e este endpoint não
 * mexe no logo; upload de logo continua exclusivo de POST /admin/event,
 * feito pelo próprio admin do evento). Único lugar que grava
 * access_expires_at/price_charged/last_payment_at/payment_notes.
 *
 * access_expires_at aqui é sempre o valor literal enviado (em branco =
 * NULL/nunca expira) — sem re-derivar de event_date como na criação.
 * A derivação automática é só uma conveniência para o primeiro valor;
 * na edição o super-admin tem controle direto e sem mágica escondida
 * (senão "deixar em branco pra manter o auto-cálculo" e "limpar de
 * propósito para nunca expirar" ficariam indistinguíveis no formulário).
 * Ao mudar o plano, a tela sugere a data nova e o super-admin confirma.
 * A única exceção é o plano Ilimitado, que sempre grava NULL (sem prazo).
 */
function handle_update_event(PDO $pdo, array $config, int $id): void
{
    $existing = fetch_event_row($pdo, $id);
    if (!$existing) {
        json_error(404, 'Evento não encontrado.');
    }

    $body = json_body();
    $fields = read_event_branding_fields($body);
    $sentFields = read_sent_branding_fields($body) + read_sent_plan_fields($body) + read_sent_quote($pdo, $body, $id)
        + read_sent_demo_field($body);
    $eventType = read_event_type($body);
    $plan = $sentFields['plan'] ?? $existing['plan'];
    $accessExpiresAt = $plan === 'unlimited' ? null : optional_datetime($body, 'access_expires_at');
    $priceCharged = optional_decimal($body, 'price_charged');
    $lastPaymentAt = optional_date($body, 'last_payment_at');
    $paymentNotes = optional_string($body, 'payment_notes', 500);

    $stmt = $pdo->prepare(
        'UPDATE events
         SET event_type = :event_type, host_name = :host_name, host_name_secondary = :host_name_secondary,
             event_date = :event_date, venue_name = :venue_name, venue_name_secondary = :venue_name_secondary,
             address = :address, maps_url = :maps_url, maps_url_secondary = :maps_url_secondary,
             dress_code = :dress_code, invite_message = :invite_message, pix_key = :pix_key,
             color_primary = :color_primary, name_font = :name_font, access_expires_at = :access_expires_at,
             price_charged = :price_charged, last_payment_at = :last_payment_at, payment_notes = :payment_notes'
        . sent_fields_set_clause($sentFields) . '
         WHERE id = :id'
    );
    $stmt->execute($fields + $sentFields + [
        'event_type' => $eventType,
        'access_expires_at' => $accessExpiresAt,
        'price_charged' => $priceCharged,
        'last_payment_at' => $lastPaymentAt,
        'payment_notes' => $paymentNotes,
        'id' => $id,
    ]);

    json_response(200, ['event' => format_super_event(fetch_event_row($pdo, $id))]);
}

/**
 * DELETE /admin/super/events/{id}
 * Remove o evento e tudo que depende dele — convidados, presentes (e seus
 * presenteios, em cascata), a ligação dos admins com ele e as contas que
 * ficarem sem nenhum evento (com sessões/tokens, em cascata) — de forma
 * irreversível. Quem administra outros eventos continua com eles.
 * guests/gifts são apagados explicitamente aqui porque a FK deles pra
 * events é ON DELETE RESTRICT de propósito (evita apagar esse conteúdo sem
 * querer em qualquer outro fluxo). As ligações saem antes do evento para
 * settle_account_events() tirar o admin_users.event_id das contas deste
 * evento: a FK dele é ON DELETE CASCADE e levaria junto uma conta que tem
 * outros eventos.
 */
function handle_delete_event(PDO $pdo, int $id): void
{
    $event = fetch_event_row($pdo, $id);
    if (!$event) {
        json_error(404, 'Evento não encontrado.');
    }

    $giftImagesStmt = $pdo->prepare(
        'SELECT image_path FROM gifts WHERE event_id = :event_id AND image_path IS NOT NULL'
    );
    $giftImagesStmt->execute(['event_id' => $id]);
    $giftImages = $giftImagesStmt->fetchAll(PDO::FETCH_COLUMN);

    $membersStmt = $pdo->prepare('SELECT admin_user_id FROM event_admins WHERE event_id = :event_id');
    $membersStmt->execute(['event_id' => $id]);
    $memberIds = array_map('intval', $membersStmt->fetchAll(PDO::FETCH_COLUMN));

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM gifts WHERE event_id = :event_id')->execute(['event_id' => $id]);
        $pdo->prepare('DELETE FROM guests WHERE event_id = :event_id')->execute(['event_id' => $id]);
        $pdo->prepare('DELETE FROM event_admins WHERE event_id = :event_id')->execute(['event_id' => $id]);
        foreach ($memberIds as $memberId) {
            settle_account_events($pdo, $memberId);
        }
        $pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_error(500, 'Não foi possível remover o evento.');
    }

    foreach ($giftImages as $path) {
        delete_uploaded_file($path);
    }
    delete_uploaded_file($event['logo_path']);
    delete_uploaded_file($event['cover_photo_path']);
    delete_uploaded_file($event['music_path']);

    json_response(200, ['ok' => true]);
}

/**
 * Calcula access_expires_at: se vier explícito no corpo, prevalece (é o
 * mecanismo de renovação manual). Senão, e se event_date estiver definida,
 * o padrão é event_date + o prazo do plano (plan_grace_days: o Premium tem
 * o dobro do Padrão). Sem event_date e sem valor explícito, fica NULL
 * (nunca expira, até o super-admin definir a data ou o valor manualmente).
 * O plano Ilimitado é sempre NULL.
 */
function compute_access_expires_at(array $body, ?string $eventDate, string $plan): ?string
{
    $graceDays = plan_grace_days($plan);
    if ($graceDays === null) {
        return null;
    }

    $raw = trim((string) ($body['access_expires_at'] ?? ''));
    if ($raw !== '') {
        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            json_error(422, 'Data de expiração de acesso inválida.');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    if ($eventDate === null) {
        return null;
    }

    $timestamp = strtotime($eventDate . " +{$graceDays} days");

    return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
}

/**
 * Liga/desliga o evento de exemplo da home (events.is_demo), só quando a
 * chave vem no corpo: uma tela que não conhece o campo não o apaga.
 */
function read_sent_demo_field(array $body): array
{
    return array_key_exists('is_demo', $body) ? ['is_demo' => read_flag($body, 'is_demo')] : [];
}

function read_event_type(array $body): string
{
    $type = trim((string) ($body['event_type'] ?? 'wedding'));
    if (!in_array($type, ['wedding', 'birthday', 'graduation', 'other'], true)) {
        json_error(422, 'Tipo de evento inválido.');
    }

    return $type;
}

function optional_decimal(array $body, string $key): ?float
{
    $raw = trim((string) ($body[$key] ?? ''));
    if ($raw === '') {
        return null;
    }

    $value = filter_var($raw, FILTER_VALIDATE_FLOAT);
    if ($value === false) {
        json_error(422, "Valor inválido: {$key}.");
    }

    return $value;
}

function optional_date(array $body, string $key): ?string
{
    $raw = trim((string) ($body[$key] ?? ''));
    if ($raw === '') {
        return null;
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        json_error(422, "Data inválida: {$key}.");
    }

    return date('Y-m-d', $timestamp);
}

function optional_datetime(array $body, string $key): ?string
{
    $raw = trim((string) ($body[$key] ?? ''));
    if ($raw === '') {
        return null;
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        json_error(422, "Data inválida: {$key}.");
    }

    return date('Y-m-d H:i:s', $timestamp);
}

/**
 * Visão do super-admin: dados do admin de evento + id interno + o que foi
 * contratado + todos os campos financeiros/de controle (price_charged,
 * last_payment_at, payment_notes, created_at).
 *
 * contract traz os valores como estão gravados, para o formulário. O
 * gifts_enabled de fora já considera o plano (o Ilimitado liga tudo).
 */
function format_super_event(array $event): array
{
    return format_admin_event($event) + [
        'id' => (int) $event['id'],
        'contract' => [
            'gifts_enabled' => (bool) $event['gifts_enabled'],
            'guest_limit' => $event['guest_limit'] !== null ? (int) $event['guest_limit'] : null,
            'admin_limit' => $event['admin_limit'] !== null ? (int) $event['admin_limit'] : null,
        ],
        'price_charged' => $event['price_charged'] !== null ? (float) $event['price_charged'] : null,
        // orçamento aplicado na venda (read_sent_quote), como estava no dia
        'price_breakdown' => $event['price_breakdown'] !== null ? json_decode($event['price_breakdown'], true) : null,
        'coupon_code' => $event['coupon_code'],
        'last_payment_at' => $event['last_payment_at'],
        'payment_notes' => $event['payment_notes'],
        'created_at' => $event['created_at'],
    ];
}
