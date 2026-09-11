<?php

declare(strict_types=1);

/**
 * GET /events/{eventSlug}
 * Dados públicos de marca do evento (nomes, data, local, cores, logo, pix)
 * para montar as páginas de convite/RSVP/presentes. Não inclui nenhum
 * campo administrativo (access_expires_at, financeiro etc) — isso é
 * exposto só por handlers/admin_event.php e handlers/super_events.php.
 */
function handle_get_event(PDO $pdo, string $eventSlug): void
{
    $event = find_event_by_slug($pdo, $eventSlug);
    if (!$event) {
        json_error(404, 'Evento não encontrado.');
    }

    json_response(200, ['event' => format_public_event($event)]);
}

/**
 * Busca um evento pelo slug. Usado por todas as rotas públicas
 * (/events/{slug}/...) para resolver o event_id antes de escopar
 * convidados/presentes por ele.
 */
function find_event_by_slug(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, slug, event_type, host_name, host_name_secondary, event_date,
                venue_name, venue_name_secondary, address, maps_url, dress_code,
                pix_key, logo_path, color_primary
         FROM events
         WHERE slug = :slug'
    );
    $stmt->execute(['slug' => $slug]);

    $event = $stmt->fetch();
    if (!$event) {
        return null;
    }

    $event['id'] = (int) $event['id'];

    return $event;
}

function format_public_event(array $event): array
{
    return [
        'slug' => $event['slug'],
        'event_type' => $event['event_type'],
        'host_name' => $event['host_name'],
        'host_name_secondary' => $event['host_name_secondary'],
        'event_date' => $event['event_date'],
        'venue_name' => $event['venue_name'],
        'venue_name_secondary' => $event['venue_name_secondary'],
        'address' => $event['address'],
        'maps_url' => $event['maps_url'],
        'dress_code' => $event['dress_code'],
        'pix_key' => $event['pix_key'],
        'logo_url' => public_asset_url($event['logo_path']),
        'color_primary' => $event['color_primary'],
    ];
}
