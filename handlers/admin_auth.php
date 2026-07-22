<?php

declare(strict_types=1);

/**
 * POST /admin/login
 * Body: { "email": "...", "password": "..." }
 */
function handle_admin_login(PDO $pdo, array $config): void
{
    $body = json_body();
    $email = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($email === '' || $password === '') {
        json_error(422, 'Informe email e senha.');
    }

    $stmt = $pdo->prepare('SELECT id, name, email, password_hash FROM admin_users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        json_error(401, 'Email ou senha inválidos.');
    }

    // limpeza oportunista de sessões expiradas
    $pdo->exec('DELETE FROM admin_sessions WHERE expires_at < NOW()');

    $token = generate_token();
    $ttlDays = (int) ($config['session_ttl_days'] ?? 7);

    $insert = $pdo->prepare(
        'INSERT INTO admin_sessions (admin_user_id, token, expires_at)
         VALUES (:admin_user_id, :token, DATE_ADD(NOW(), INTERVAL :ttl DAY))'
    );
    $insert->execute([
        'admin_user_id' => $admin['id'],
        'token' => $token,
        'ttl' => $ttlDays,
    ]);

    json_response(200, [
        'token' => $token,
        'admin' => [
            'id' => (int) $admin['id'],
            'name' => $admin['name'],
            'email' => $admin['email'],
        ],
    ]);
}

/**
 * POST /admin/logout
 */
function handle_admin_logout(PDO $pdo, array $admin): void
{
    $stmt = $pdo->prepare('DELETE FROM admin_sessions WHERE token = :token');
    $stmt->execute(['token' => $admin['_token']]);

    json_response(200, ['ok' => true]);
}
