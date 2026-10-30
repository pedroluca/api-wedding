<?php

declare(strict_types=1);

/**
 * Contas de admin e os eventos de cada uma (migração 021).
 *
 * Uma conta (email + senha) administra um ou mais eventos (event_admins), e
 * a sessão guarda o evento ativo (admin_sessions.event_id). É ele que
 * require_admin() devolve em $admin['event_id'], então as rotas do painel
 * do evento continuam sem saber que a conta tem outros eventos.
 *
 * admin_users.event_id ficou só por compatibilidade: a API mantém nele um
 * dos eventos da conta (NULL só no super), para a API anterior à 021 nunca
 * enxergar um cliente como super num rollback. Ver settle_account_events().
 */

/**
 * Eventos que a conta administra, do mais provável de ser o que a pessoa
 * quer abrir para o menos (ver sort_account_events()). Também traz o
 * admin_users.event_id da conta, mesmo sem ligação em event_admins: é o
 * caso de uma conta criada pela API anterior entre a migração e o deploy.
 */
function fetch_account_events(PDO $pdo, int $adminId): array
{
    $stmt = $pdo->prepare(
        'SELECT e.id, e.slug, e.event_type, e.host_name, e.host_name_secondary, e.subtitle, e.event_date,
                e.access_expires_at, e.plan, e.gifts_enabled, e.guest_limit, e.admin_limit, e.created_at
         FROM events e
         WHERE e.id IN (SELECT ea.event_id FROM event_admins ea WHERE ea.admin_user_id = :member_id)
            OR e.id = (SELECT au.event_id FROM admin_users au WHERE au.id = :legacy_id AND au.is_super = 0)'
    );
    $stmt->execute(['member_id' => $adminId, 'legacy_id' => $adminId]);

    return sort_account_events($stmt->fetchAll());
}

/**
 * Ordem da lista "Seus eventos", e o primeiro é onde o login cai: eventos
 * com acesso primeiro; entre eles, os que ainda vão acontecer (o mais
 * próximo antes), depois os sem data ou já passados (o criado por último
 * antes). Os de acesso encerrado vão para o fim.
 */
function sort_account_events(array $events): array
{
    $today = date('Y-m-d');
    $key = static function (array $event) use ($today): array {
        $date = $event['event_date'] !== null ? substr((string) $event['event_date'], 0, 10) : null;
        $upcoming = $date !== null && $date >= $today;

        return [
            event_access_expired($event) ? 1 : 0,
            $upcoming ? 0 : 1,
            $upcoming ? strtotime($date) : -strtotime((string) $event['created_at']),
        ];
    };

    usort($events, static fn (array $a, array $b): int => $key($a) <=> $key($b));

    return $events;
}

function format_account_event(array $event): array
{
    return [
        'id' => (int) $event['id'],
        'slug' => $event['slug'],
        'event_type' => $event['event_type'],
        'host_name' => $event['host_name'],
        'host_name_secondary' => $event['host_name_secondary'],
        'subtitle' => $event['subtitle'],
        'event_date' => $event['event_date'],
        'access_expired' => event_access_expired($event),
    ];
}

/**
 * Resposta do login, de "entrar como admin", de GET /admin/me e da troca de
 * evento: a conta, o evento ativo e os eventos dela. admin.event_id é o
 * evento ativo (NULL no super), o mesmo campo que o front anterior à 021 lê.
 *
 * @param array $account id, name, email e is_super da conta
 * @param array $events linhas de fetch_account_events()
 */
function account_session_payload(array $account, ?int $activeEventId, array $events): array
{
    return [
        'admin' => [
            'id' => (int) $account['id'],
            'event_id' => $activeEventId,
            'name' => $account['name'],
            'email' => $account['email'],
            'is_super' => (bool) $account['is_super'],
        ],
        'events' => array_map('format_account_event', $events),
    ];
}

function find_account_by_email(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT id, name, email, is_super FROM admin_users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $account = $stmt->fetch();

    return $account ?: null;
}

function is_event_admin(PDO $pdo, int $eventId, int $adminId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM event_admins WHERE event_id = :event_id AND admin_user_id = :admin_id');
    $stmt->execute(['event_id' => $eventId, 'admin_id' => $adminId]);

    return (bool) $stmt->fetchColumn();
}

function add_event_membership(PDO $pdo, int $eventId, int $adminId): void
{
    $pdo->prepare('INSERT IGNORE INTO event_admins (event_id, admin_user_id) VALUES (:event_id, :admin_id)')
        ->execute(['event_id' => $eventId, 'admin_id' => $adminId]);
}

/**
 * Acerta a conta depois de ela sair de um evento: admin_users.event_id passa
 * para outro evento dela (o de menor id), e a conta que ficou sem nenhum é
 * apagada, com sessões e tokens (em cascata). O super nunca é tocado.
 */
function settle_account_events(PDO $pdo, int $adminId): void
{
    $stmt = $pdo->prepare('SELECT MIN(event_id) FROM event_admins WHERE admin_user_id = :id');
    $stmt->execute(['id' => $adminId]);
    $remaining = $stmt->fetchColumn();

    if ($remaining === null || $remaining === false) {
        $pdo->prepare('DELETE FROM admin_users WHERE id = :id AND is_super = 0')->execute(['id' => $adminId]);
        return;
    }

    $pdo->prepare('UPDATE admin_users SET event_id = :event_id WHERE id = :id AND is_super = 0')
        ->execute(['event_id' => (int) $remaining, 'id' => $adminId]);
}
