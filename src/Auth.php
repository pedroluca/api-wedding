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
        'SELECT au.id, au.name, au.email
         FROM admin_sessions s
         INNER JOIN admin_users au ON au.id = s.admin_user_id
         WHERE s.token = :token AND s.expires_at > NOW()'
    );
    $stmt->execute(['token' => $token]);
    $admin = $stmt->fetch();

    if (!$admin) {
        json_error(401, 'Sessão inválida ou expirada.');
    }

    $admin['_token'] = $token;

    return $admin;
}
