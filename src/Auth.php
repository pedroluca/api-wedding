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
 */
function require_admin(PDO $pdo): array
{
    $token = bearer_token_from_request();
    if ($token === null) {
        json_error(401, 'Não autenticado.');
    }

    $stmt = $pdo->prepare(
        'SELECT au.id, au.event_id, au.name, au.email
         FROM admin_sessions s
         INNER JOIN admin_users au ON au.id = s.admin_user_id
         WHERE s.token = :token AND s.expires_at > NOW()'
    );
    $stmt->execute(['token' => $token]);
    $admin = $stmt->fetch();

    if (!$admin) {
        json_error(401, 'Sessão inválida ou expirada.');
    }

    $admin['id'] = (int) $admin['id'];
    $admin['event_id'] = $admin['event_id'] !== null ? (int) $admin['event_id'] : null;
    $admin['_token'] = $token;

    return $admin;
}

/**
 * Como require_admin(), mas exige que a sessão seja de um admin superior
 * (event_id NULL). event_id IS NULL é o único sinal de papel do sistema —
 * ver comentário em sql/schema.sql sobre admin_users.event_id.
 */
function require_super_admin(PDO $pdo): array
{
    $admin = require_admin($pdo);
    if ($admin['event_id'] !== null) {
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
 */
function require_event_admin(PDO $pdo): array
{
    $admin = require_admin($pdo);
    if ($admin['event_id'] === null) {
        json_error(403, 'Esta ação requer um admin de evento.');
    }

    $stmt = $pdo->prepare('SELECT access_expires_at FROM events WHERE id = :id');
    $stmt->execute(['id' => $admin['event_id']]);
    $event = $stmt->fetch();

    if ($event && $event['access_expires_at'] !== null && strtotime($event['access_expires_at']) <= time()) {
        json_error(403, 'O acesso a este evento expirou. Entre em contato com o suporte.', 'access_expired');
    }

    return $admin;
}
