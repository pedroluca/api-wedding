<?php

declare(strict_types=1);

function generate_token(): string
{
    return bin2hex(random_bytes(32));
}

function bearer_token_from_request(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
        return null;
    }

    return $matches[1];
}

/**
 * Valida o Bearer token e retorna os dados do admin autenticado
 * (interrompe a requisição com 401 se inválido/expirado).
 *
 * $admin['event_id'] é o evento ativo da sessão (admin_sessions.event_id),
 * não da conta: uma conta pode ter vários eventos (ver src/Accounts.php).
 * Sessão sem evento de uma conta de cliente (aberta pela API anterior à
 * 021) usa o admin_users.event_id da conta. Se a conta não administra mais
 * o evento da sessão, a sessão deixa de valer.
 */
function require_admin(PDO $pdo): array
{
    $token = bearer_token_from_request();
    if ($token === null) {
        json_error(401, 'Não autenticado.');
    }

    $stmt = $pdo->prepare(
        'SELECT au.id, au.is_super, au.name, au.email, s.impersonated_by,
                COALESCE(s.event_id, CASE WHEN au.is_super = 0 THEN au.event_id END) AS event_id,
                (ea.admin_user_id IS NOT NULL OR au.event_id = COALESCE(s.event_id, au.event_id)) AS is_member
         FROM admin_sessions s
         INNER JOIN admin_users au ON au.id = s.admin_user_id
         LEFT JOIN event_admins ea
           ON ea.admin_user_id = au.id AND ea.event_id = COALESCE(s.event_id, au.event_id)
         WHERE s.token = :token AND s.expires_at > NOW()'
    );
    $stmt->execute(['token' => $token]);
    $admin = $stmt->fetch();

    if (!$admin) {
        json_error(401, 'Sessão inválida ou expirada.');
    }
    if ($admin['event_id'] !== null && !$admin['is_member']) {
        json_error(401, 'Você não tem mais acesso a este evento.');
    }

    $admin['id'] = (int) $admin['id'];
    $admin['is_super'] = (bool) $admin['is_super'];
    $admin['event_id'] = $admin['event_id'] !== null ? (int) $admin['event_id'] : null;
    $admin['impersonated_by'] = $admin['impersonated_by'] !== null ? (int) $admin['impersonated_by'] : null;
    $admin['_token'] = $token;
    unset($admin['is_member']);

    return $admin;
}

/**
 * Como require_admin(), mas exige que a sessão seja de um admin superior
 * (admin_users.is_super, ver sql/schema.sql).
 */
function require_super_admin(PDO $pdo): array
{
    $admin = require_admin($pdo);
    if (!$admin['is_super']) {
        json_error(403, 'Acesso restrito ao super-admin.');
    }

    return $admin;
}

/**
 * Como require_admin(), mas exige que a sessão seja de um admin de evento
 * (event_id não-nulo) e que o acesso daquele evento não tenha expirado.
 * Toda rota administrativa escopada a um evento (convidados, presentes,
 * configurações do próprio evento) passa por aqui — por isso a checagem de
 * expiração vive nesta função só, e não em cada rota individualmente.
 *
 * Rotas públicas (/events/:slug/...) nunca chamam este guard: convidados
 * continuam acessando o convite normalmente mesmo com o acesso do
 * organizador expirado — só o painel admin daquele evento é bloqueado.
 * A exceção é a sessão aberta pelo super-admin ("entrar como admin"), que
 * existe justamente pra dar suporte, inclusive a evento expirado. O plano
 * Ilimitado também ignora a data, como defesa extra (ele já grava NULL).
 */
function require_event_admin(PDO $pdo): array
{
    $admin = require_admin($pdo);
    if ($admin['event_id'] === null) {
        json_error(403, 'Esta ação requer um admin de evento.');
    }

    if ($admin['impersonated_by'] !== null) {
        return $admin;
    }

    $stmt = $pdo->prepare(
        'SELECT access_expires_at, plan, gifts_enabled, guest_limit, admin_limit FROM events WHERE id = :id'
    );
    $stmt->execute(['id' => $admin['event_id']]);
    $event = $stmt->fetch();

    if ($event && event_access_expired($event)) {
        json_error(403, 'O acesso a este evento expirou. Entre em contato com o suporte.', 'access_expired');
    }

    return $admin;
}

/**
 * O painel do evento já fechou? O prazo vem de access_expires_at, e o
 * plano Ilimitado nunca fecha (ele já grava NULL; aqui é defesa extra).
 * Também marca os eventos encerrados na lista "Seus eventos".
 *
 * @param array $event linha de events com access_expires_at, plan, gifts_enabled, guest_limit e admin_limit
 */
function event_access_expired(array $event): bool
{
    return event_entitlements($event)['access_expires']
        && $event['access_expires_at'] !== null
        && strtotime($event['access_expires_at']) <= time();
}
