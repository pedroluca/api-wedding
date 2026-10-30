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
        'SELECT id, slug, event_type, host_name, host_name_secondary, subtitle, event_date,
                venue_name, venue_name_secondary, address, maps_url, maps_url_secondary, dress_code,
                invite_message, pix_key, logo_path, cover_photo_path, cover_focus_x, cover_focus_y, music_path,
                color_primary, name_font,
                invite_style, show_countdown, show_calendar, plan, gifts_enabled, guest_limit, admin_limit,
                is_demo
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

/**
 * Evento de exemplo da página inicial: o mesmo link é aberto por qualquer
 * visitante, então as rotas públicas dele respondem como se gravassem, mas
 * não gravam nada (confirmação, presente escolhido, acesso à lista).
 */
function is_demo_event(array $event): bool
{
    return (bool) ($event['is_demo'] ?? false);
}

/**
 * gifts_enabled já considera o plano (event_entitlements): é o que o
 * convite mostra. Com o módulo de presentes desligado, a chave Pix também
 * não sai daqui. show_credit: o "Feito com Presenzo" no rodapé do convite,
 * que some só nos eventos Premium.
 *
 * O evento de exemplo nunca mostra a chave Pix: um visitante poderia pagar
 * pra ela achando que é de verdade. O convite mostra um aviso no lugar.
 */
function format_public_event(array $event): array
{
    $entitlements = event_entitlements($event);
    $gifts = $entitlements['gifts'];
    $demo = is_demo_event($event);

    return [
        'slug' => $event['slug'],
        'event_type' => $event['event_type'],
        'host_name' => $event['host_name'],
        'host_name_secondary' => $event['host_name_secondary'],
        'subtitle' => $event['subtitle'],
        'event_date' => $event['event_date'],
        'venue_name' => $event['venue_name'],
        'venue_name_secondary' => $event['venue_name_secondary'],
        'address' => $event['address'],
        'maps_url' => $event['maps_url'],
        'maps_url_secondary' => $event['maps_url_secondary'],
        'dress_code' => $event['dress_code'],
        'invite_message' => $event['invite_message'],
        'pix_key' => $gifts && !$demo ? $event['pix_key'] : null,
        'logo_url' => public_asset_url($event['logo_path']),
        'cover_photo_url' => public_asset_url($event['cover_photo_path']),
        'cover_focus_x' => (int) $event['cover_focus_x'],
        'cover_focus_y' => (int) $event['cover_focus_y'],
        'music_url' => public_asset_url($event['music_path']),
        'color_primary' => $event['color_primary'],
        'name_font' => $event['name_font'],
        'invite_style' => $event['invite_style'],
        'show_countdown' => (bool) $event['show_countdown'],
        'show_calendar' => (bool) $event['show_calendar'],
        'gifts_enabled' => $gifts,
        'show_credit' => !$entitlements['hide_credit'],
        'is_demo' => $demo,
    ];
}

/**
 * GET /demos
 * Eventos de exemplo para a vitrine da página inicial, na ordem dos tipos
 * (casamento, aniversário, formatura, celebração), cada um com o link do
 * primeiro convite cadastrado nele. Exemplo ainda sem convidado fica de fora.
 */
function handle_list_demos(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT e.slug, e.event_type, e.host_name, e.host_name_secondary, e.subtitle, e.color_primary,
                e.name_font, e.invite_style, e.cover_photo_path, e.cover_focus_x, e.cover_focus_y,
                (SELECT g.slug FROM guests g
                 WHERE g.event_id = e.id AND g.related_to_id IS NULL
                 ORDER BY g.id ASC LIMIT 1) AS guest_slug
         FROM events e
         WHERE e.is_demo = 1
         ORDER BY FIELD(e.event_type, 'wedding', 'birthday', 'graduation', 'other'), e.id ASC"
    );

    $demos = [];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['guest_slug'] === null) {
            continue;
        }

        $demos[] = [
            'slug' => $row['slug'],
            'guest_slug' => $row['guest_slug'],
            'event_type' => $row['event_type'],
            'host_name' => $row['host_name'],
            'host_name_secondary' => $row['host_name_secondary'],
            'subtitle' => $row['subtitle'],
            'color_primary' => $row['color_primary'],
            'name_font' => $row['name_font'],
            'invite_style' => $row['invite_style'],
            'cover_photo_url' => public_asset_url($row['cover_photo_path']),
            'cover_focus_x' => (int) $row['cover_focus_x'],
            'cover_focus_y' => (int) $row['cover_focus_y'],
        ];
    }

    json_response(200, ['demos' => $demos]);
}
