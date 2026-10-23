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
 * multipart/form-data com os campos de marca do evento + arquivos opcionais
 * (logo, cover_photo, music). remove_cover_photo=1 / remove_music=1 apagam
 * a foto de capa / música atuais (o logo só pode ser substituído).
 * Só escreve campos de marca — access_expires_at/price_charged/event_type
 * etc só são graváveis pelo super-admin, mesmo que venham no corpo.
 * share_message fica fora de read_event_branding_fields de propósito: é
 * só do admin do evento, e assim o PUT do super-admin nunca o apaga.
 * Campos mais novos (read_sent_branding_fields) só mudam quando vêm no corpo.
 */
function handle_update_own_event(PDO $pdo, int $eventId): void
{
    $existing = fetch_event_row($pdo, $eventId);
    if (!$existing) {
        json_error(404, 'Evento não encontrado.');
    }

    $fields = read_event_branding_fields($_POST);
    $fields['share_message'] = optional_text($_POST, 'share_message', 1000);
    $sentFields = read_sent_branding_fields($_POST);

    $logoPath = $existing['logo_path'];
    if (has_uploaded_file('logo')) {
        $logoPath = save_uploaded_image($_FILES['logo'], 'logos');
    }

    $coverPhotoPath = $existing['cover_photo_path'];
    if (has_uploaded_file('cover_photo')) {
        $coverPhotoPath = save_uploaded_image($_FILES['cover_photo'], 'covers');
    } elseif (($_POST['remove_cover_photo'] ?? '') === '1') {
        $coverPhotoPath = null;
    }

    $musicPath = $existing['music_path'];
    if (has_uploaded_file('music')) {
        $musicPath = save_uploaded_audio($_FILES['music'], 'music');
    } elseif (($_POST['remove_music'] ?? '') === '1') {
        $musicPath = null;
    }

    $stmt = $pdo->prepare(
        'UPDATE events
         SET host_name = :host_name, host_name_secondary = :host_name_secondary,
             event_date = :event_date, venue_name = :venue_name,
             venue_name_secondary = :venue_name_secondary, address = :address,
             maps_url = :maps_url, maps_url_secondary = :maps_url_secondary,
             dress_code = :dress_code, invite_message = :invite_message,
             share_message = :share_message, pix_key = :pix_key,
             color_primary = :color_primary, name_font = :name_font, logo_path = :logo_path,
             cover_photo_path = :cover_photo_path, music_path = :music_path'
        . sent_fields_set_clause($sentFields) . '
         WHERE id = :id'
    );
    $stmt->execute($fields + $sentFields + [
        'logo_path' => $logoPath,
        'cover_photo_path' => $coverPhotoPath,
        'music_path' => $musicPath,
        'id' => $eventId,
    ]);

    // Só apaga os arquivos antigos depois do UPDATE dar certo, pra nunca
    // deixar o banco apontando pra um arquivo que já não existe.
    foreach (['logo_path' => $logoPath, 'cover_photo_path' => $coverPhotoPath, 'music_path' => $musicPath] as $column => $path) {
        if ($existing[$column] !== $path) {
            delete_uploaded_file($existing[$column]);
        }
    }

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
        'SELECT id, slug, event_type, host_name, host_name_secondary, subtitle, event_date,
                venue_name, venue_name_secondary, address, maps_url, maps_url_secondary, dress_code,
                invite_message, share_message, pix_key, logo_path, cover_photo_path, music_path,
                color_primary, name_font, access_expires_at, price_charged, last_payment_at,
                payment_notes, created_at
         FROM events
         WHERE id = :id'
    );
    $stmt->execute(['id' => $eventId]);

    return $stmt->fetch() ?: null;
}

/**
 * Visão do admin de evento: dados públicos + access_expires_at (para saber
 * quando precisa renovar) + modelo da mensagem de envio, sem nenhum campo
 * financeiro.
 */
function format_admin_event(array $event): array
{
    return format_public_event($event) + [
        'access_expires_at' => $event['access_expires_at'],
        'share_message' => $event['share_message'],
    ];
}

/**
 * Lê e valida os campos de marca do evento a partir de um corpo de
 * requisição (multipart/form-data, via $_POST). Compartilhado entre
 * handle_update_own_event() (admin de evento) e o CRUD do super-admin.
 *
 * @return array{host_name:string,host_name_secondary:?string,event_date:?string,
 *               venue_name:?string,venue_name_secondary:?string,address:?string,
 *               maps_url:?string,maps_url_secondary:?string,dress_code:?string,
 *               invite_message:?string,pix_key:?string,color_primary:string,
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
        'maps_url_secondary' => optional_string($body, 'maps_url_secondary', 500),
        'dress_code' => optional_string($body, 'dress_code', 150),
        'invite_message' => optional_text($body, 'invite_message', 1000),
        'pix_key' => optional_string($body, 'pix_key', 190),
        'color_primary' => $colorPrimary,
        'name_font' => $nameFont,
    ];
}

/**
 * Campos de marca que chegaram depois dos formulários originais: só entram
 * no UPDATE quando vêm no corpo. Como a API sobe antes do front em cada
 * deploy, um front ainda sem o campo salva as configurações sem apagá-lo.
 * Para limpar, o front manda o campo vazio.
 *
 * @return array<string, ?string> coluna => valor, só com os campos enviados
 */
function read_sent_branding_fields(array $body): array
{
    $fields = [];
    if (array_key_exists('subtitle', $body)) {
        $fields['subtitle'] = optional_string($body, 'subtitle', 150);
    }

    return $fields;
}

/** ", subtitle = :subtitle" para os campos de read_sent_branding_fields(). */
function sent_fields_set_clause(array $sentFields): string
{
    return implode('', array_map(
        static fn (string $column): string => ", {$column} = :{$column}",
        array_keys($sentFields)
    ));
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

/**
 * optional_string() para textos com várias linhas (textarea). O
 * multipart/form-data envia quebras de linha como \r\n; normaliza pra \n
 * antes do limite de tamanho, senão cada quebra contaria em dobro.
 */
function optional_text(array $body, string $key, int $maxLength): ?string
{
    if (isset($body[$key])) {
        $body[$key] = str_replace("\r\n", "\n", (string) $body[$key]);
    }

    return optional_string($body, $key, $maxLength);
}
