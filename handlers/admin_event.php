<?php

declare(strict_types=1);

/**
 * GET /admin/event
 * Dados completos do próprio evento do admin autenticado, incluindo
 * access_expires_at (para saber quando precisa renovar) — mas nunca os
 * campos financeiros (price_charged etc), que só o super-admin vê
 * (handlers/super_events.php).
 */
function handle_get_own_event(PDO $pdo, int $eventId): void
{
    $event = fetch_event_row($pdo, $eventId);
    if (!$event) {
        json_error(404, 'Evento não encontrado.');
    }

    json_response(200, ['event' => format_admin_event($event)]);
}

/**
 * POST /admin/event
 * multipart/form-data com os campos de marca do evento + logo opcional.
 * Só escreve campos de marca — access_expires_at/price_charged/event_type
 * etc só são graváveis pelo super-admin, mesmo que venham no corpo.
 */
function handle_update_own_event(PDO $pdo, int $eventId): void
{
    $existing = fetch_event_row($pdo, $eventId);
    if (!$existing) {
        json_error(404, 'Evento não encontrado.');
    }

    $fields = read_event_branding_fields($_POST);

    $logoPath = $existing['logo_path'];
    if (has_uploaded_file('logo')) {
        $logoPath = save_uploaded_image($_FILES['logo'], 'logos');
        delete_uploaded_file($existing['logo_path']);
    }

    $stmt = $pdo->prepare(
        'UPDATE events
         SET host_name = :host_name, host_name_secondary = :host_name_secondary,
             event_date = :event_date, venue_name = :venue_name,
             venue_name_secondary = :venue_name_secondary, address = :address,
             maps_url = :maps_url, dress_code = :dress_code, pix_key = :pix_key,
             color_primary = :color_primary, name_font = :name_font, logo_path = :logo_path
         WHERE id = :id'
    );
    $stmt->execute($fields + ['logo_path' => $logoPath, 'id' => $eventId]);

    json_response(200, ['event' => format_admin_event(fetch_event_row($pdo, $eventId))]);
}

/**
 * Busca um evento pelo id com todas as colunas (marca + acesso +
 * financeiro). Reaproveitada por handlers/super_events.php, que expõe o
 * conjunto completo — os formatadores abaixo é que decidem o que cada
 * nível de admin efetivamente vê.
 */
function fetch_event_row(PDO $pdo, int $eventId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, slug, event_type, host_name, host_name_secondary, event_date,
                venue_name, venue_name_secondary, address, maps_url, dress_code,
                pix_key, logo_path, color_primary, name_font, access_expires_at,
                price_charged, last_payment_at, payment_notes, created_at
         FROM events
         WHERE id = :id'
    );
    $stmt->execute(['id' => $eventId]);

    return $stmt->fetch() ?: null;
}

/**
 * Visão do admin de evento: dados públicos + access_expires_at (para saber
 * quando precisa renovar), sem nenhum campo financeiro.
 */
function format_admin_event(array $event): array
{
    return format_public_event($event) + [
        'access_expires_at' => $event['access_expires_at'],
    ];
}

/**
 * Lê e valida os campos de marca do evento a partir de um corpo de
 * requisição (multipart/form-data, via $_POST). Compartilhado entre
 * handle_update_own_event() (admin de evento) e o CRUD do super-admin.
 *
 * @return array{host_name:string,host_name_secondary:?string,event_date:?string,
 *               venue_name:?string,venue_name_secondary:?string,address:?string,
 *               maps_url:?string,dress_code:?string,pix_key:?string,color_primary:string,
 *               name_font:string}
 */
function read_event_branding_fields(array $body): array
{
    $colorPrimary = trim((string) ($body['color_primary'] ?? ''));
    if ($colorPrimary === '') {
        $colorPrimary = '#d2afff';
    } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $colorPrimary)) {
        json_error(422, 'Cor inválida. Use o formato #rrggbb.');
    }

    $nameFont = trim((string) ($body['name_font'] ?? ''));
    if ($nameFont === '') {
        $nameFont = 'sans';
    } elseif (!in_array($nameFont, ['sans', 'fleur', 'pinyon'], true)) {
        json_error(422, 'Fonte do nome inválida.');
    }

    $eventDate = null;
    $rawDate = trim((string) ($body['event_date'] ?? ''));
    if ($rawDate !== '') {
        $timestamp = strtotime($rawDate);
        if ($timestamp === false) {
            json_error(422, 'Data do evento inválida.');
        }
        $eventDate = date('Y-m-d H:i:s', $timestamp);
    }

    return [
        'host_name' => require_string($body, 'host_name', 150),
        'host_name_secondary' => optional_string($body, 'host_name_secondary', 150),
        'event_date' => $eventDate,
        'venue_name' => optional_string($body, 'venue_name', 190),
        'venue_name_secondary' => optional_string($body, 'venue_name_secondary', 190),
        'address' => optional_string($body, 'address', 255),
        'maps_url' => optional_string($body, 'maps_url', 500),
        'dress_code' => optional_string($body, 'dress_code', 150),
        'pix_key' => optional_string($body, 'pix_key', 190),
        'color_primary' => $colorPrimary,
        'name_font' => $nameFont,
    ];
}

function optional_string(array $body, string $key, int $maxLength): ?string
{
    $value = trim((string) ($body[$key] ?? ''));
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value) > $maxLength) {
        json_error(422, "Campo muito longo: {$key}.");
    }

    return $value;
}
