<?php

declare(strict_types=1);

/**
 * GET /admin/super/events
 */
function handle_list_events(PDO $pdo): void
{
    $stmt = $pdo->query(
        'SELECT id, slug, event_type, host_name, host_name_secondary, event_date,
                venue_name, venue_name_secondary, address, maps_url, dress_code,
                pix_key, logo_path, color_primary, name_font, access_expires_at,
                price_charged, last_payment_at, payment_notes, created_at
         FROM events
         ORDER BY created_at DESC'
    );

    json_response(200, ['events' => array_map('format_super_event', $stmt->fetchAll())]);
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

    json_response(200, ['event' => format_super_event($event)]);
}

/**
 * POST /admin/super/events
 * multipart/form-data: campos de marca (ver read_event_branding_fields),
 * event_type, logo opcional, e admin_name/admin_email do primeiro admin do
 * evento. Cria o evento (com slug gerado a partir dos nomes), o primeiro
 * admin (senha inutilizável) e dispara o email de convite.
 */
function handle_create_event(PDO $pdo, array $config): void
{
    $fields = read_event_branding_fields($_POST);
    $eventType = read_event_type($_POST);

    $adminName = require_string($_POST, 'admin_name', 150);
    $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
    if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        json_error(422, 'Informe um email válido para o admin do evento.');
    }

    $emailTaken = $pdo->prepare('SELECT id FROM admin_users WHERE email = :email');
    $emailTaken->execute(['email' => $adminEmail]);
    if ($emailTaken->fetch()) {
        json_error(409, 'Já existe um admin cadastrado com este email.');
    }

    $logoPath = has_uploaded_file('logo') ? save_uploaded_image($_FILES['logo'], 'logos') : null;
    $accessExpiresAt = compute_access_expires_at($_POST, $fields['event_date'], $config);

    $pdo->beginTransaction();
    try {
        $slug = generate_event_slug($pdo, $fields['host_name'], $fields['host_name_secondary']);

        $insert = $pdo->prepare(
            'INSERT INTO events
               (slug, event_type, host_name, host_name_secondary, event_date, venue_name,
                venue_name_secondary, address, maps_url, dress_code, pix_key, logo_path,
                color_primary, name_font, access_expires_at)
             VALUES
               (:slug, :event_type, :host_name, :host_name_secondary, :event_date, :venue_name,
                :venue_name_secondary, :address, :maps_url, :dress_code, :pix_key, :logo_path,
                :color_primary, :name_font, :access_expires_at)'
        );
        $insert->execute($fields + [
            'slug' => $slug,
            'event_type' => $eventType,
            'logo_path' => $logoPath,
            'access_expires_at' => $accessExpiresAt,
        ]);
        $eventId = (int) $pdo->lastInsertId();

        $adminId = create_event_admin($pdo, $eventId, $adminName, $adminEmail);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_error(500, 'Não foi possível criar o evento.');
    }

    // Fora da transação: envio de email é I/O externo e, se falhar, o admin
    // recém-criado ainda pode recuperar acesso depois via "esqueci minha senha".
    $eventLabel = format_event_label($eventType, $fields['host_name'], $fields['host_name_secondary']);
    issue_and_send_password_token($pdo, $config, $adminId, $adminName, $adminEmail, 'invite', $eventLabel);

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
 */
function handle_update_event(PDO $pdo, array $config, int $id): void
{
    $existing = fetch_event_row($pdo, $id);
    if (!$existing) {
        json_error(404, 'Evento não encontrado.');
    }

    $body = json_body();
    $fields = read_event_branding_fields($body);
    $eventType = read_event_type($body);
    $accessExpiresAt = optional_datetime($body, 'access_expires_at');
    $priceCharged = optional_decimal($body, 'price_charged');
    $lastPaymentAt = optional_date($body, 'last_payment_at');
    $paymentNotes = optional_string($body, 'payment_notes', 500);

    $stmt = $pdo->prepare(
        'UPDATE events
         SET event_type = :event_type, host_name = :host_name, host_name_secondary = :host_name_secondary,
             event_date = :event_date, venue_name = :venue_name, venue_name_secondary = :venue_name_secondary,
             address = :address, maps_url = :maps_url, dress_code = :dress_code, pix_key = :pix_key,
             color_primary = :color_primary, name_font = :name_font, access_expires_at = :access_expires_at,
             price_charged = :price_charged, last_payment_at = :last_payment_at, payment_notes = :payment_notes
         WHERE id = :id'
    );
    $stmt->execute($fields + [
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
 * presenteios, em cascata), admins do evento (e as sessões/tokens deles, em
 * cascata) — de forma irreversível. guests/gifts são apagados explicitamente
 * aqui porque a FK deles pra events é ON DELETE RESTRICT de propósito (evita
 * apagar esse conteúdo sem querer em qualquer outro fluxo); admin_users usa
 * ON DELETE CASCADE, então basta remover o evento por último.
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

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM gifts WHERE event_id = :event_id')->execute(['event_id' => $id]);
        $pdo->prepare('DELETE FROM guests WHERE event_id = :event_id')->execute(['event_id' => $id]);
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

    json_response(200, ['ok' => true]);
}

/**
 * Calcula access_expires_at: se vier explícito no corpo, prevalece (é o
 * mecanismo de renovação manual). Senão, e se event_date estiver definida,
 * o padrão é event_date + config('access.grace_days') dias. Sem
 * event_date e sem valor explícito, fica NULL (nunca expira, até o
 * super-admin definir a data ou o valor manualmente).
 */
function compute_access_expires_at(array $body, ?string $eventDate, array $config): ?string
{
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

    $graceDays = (int) ($config['access']['grace_days'] ?? 15);
    $timestamp = strtotime($eventDate . " +{$graceDays} days");

    return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
}

function read_event_type(array $body): string
{
    $type = trim((string) ($body['event_type'] ?? 'wedding'));
    if (!in_array($type, ['wedding', 'birthday'], true)) {
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
 * Visão do super-admin: dados do admin de evento + id interno + todos os
 * campos financeiros/de controle (price_charged, last_payment_at,
 * payment_notes, created_at).
 */
function format_super_event(array $event): array
{
    return format_admin_event($event) + [
        'id' => (int) $event['id'],
        'price_charged' => $event['price_charged'] !== null ? (float) $event['price_charged'] : null,
        'last_payment_at' => $event['last_payment_at'],
        'payment_notes' => $event['payment_notes'],
        'created_at' => $event['created_at'],
    ];
}
