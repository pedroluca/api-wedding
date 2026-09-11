<?php

declare(strict_types=1);

/**
 * POST /admin/forgot-password
 * Body: { "email": "..." }
 * Sempre responde com a mesma mensagem genérica, exista ou não o email
 * cadastrado, para não permitir enumeração de contas.
 */
function handle_forgot_password(PDO $pdo, array $config): void
{
    $body = json_body();
    $email = trim((string) ($body['email'] ?? ''));

    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT id, name FROM admin_users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $admin = $stmt->fetch();

        if ($admin) {
            issue_and_send_password_token($pdo, $config, (int) $admin['id'], $admin['name'], $email, 'reset');
        }
    }

    json_response(200, [
        'ok' => true,
        'message' => 'Se este email estiver cadastrado, enviaremos um link para redefinir a senha.',
    ]);
}

/**
 * POST /admin/reset-password
 * Body: { "token": "...", "password": "..." }
 * Usado tanto para "esqueci minha senha" quanto para um admin recém
 * convidado definir a própria senha pela primeira vez — mesmo mecanismo
 * de token, só o email enviado antes muda de texto (purpose).
 */
function handle_reset_password(PDO $pdo): void
{
    $body = json_body();
    $token = trim((string) ($body['token'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($token === '') {
        json_error(422, 'Token inválido.');
    }
    if (mb_strlen($password) < 8) {
        json_error(422, 'A senha precisa ter ao menos 8 caracteres.');
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT id, admin_user_id FROM admin_password_tokens
         WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW()'
    );
    $stmt->execute(['token_hash' => $tokenHash]);
    $tokenRow = $stmt->fetch();

    if (!$tokenRow) {
        json_error(400, 'Link inválido ou expirado. Solicite um novo.');
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id');
        $update->execute([
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $tokenRow['admin_user_id'],
        ]);

        $markUsed = $pdo->prepare('UPDATE admin_password_tokens SET used_at = NOW() WHERE id = :id');
        $markUsed->execute(['id' => $tokenRow['id']]);

        // Invalida sessões antigas: a senha pode estar sendo redefinida
        // justamente porque foi comprometida, ou o admin trocou de dispositivo.
        $pdo->prepare('DELETE FROM admin_sessions WHERE admin_user_id = :id')
            ->execute(['id' => $tokenRow['admin_user_id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error(500, 'Não foi possível redefinir a senha.');
    }

    json_response(200, ['ok' => true]);
}

/**
 * Gera um token de definição de senha (convite ou redefinição), guarda só
 * o hash no banco (o token bruto só existe no link do email) e dispara o
 * email correspondente. Reaproveitada por handle_forgot_password() aqui e
 * por handlers/super_admins.php e handlers/super_events.php ao convidar um
 * novo admin de evento — que passam $eventLabel (ver format_event_label()
 * em src/Mailer.php) pra quem recebe o convite situar de cara qual evento é.
 */
function issue_and_send_password_token(
    PDO $pdo,
    array $config,
    int $adminUserId,
    string $adminName,
    string $adminEmail,
    string $purpose,
    ?string $eventLabel = null
): void {
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $ttlHours = (int) ($config['password_token_ttl_hours'][$purpose] ?? 24);

    $stmt = $pdo->prepare(
        'INSERT INTO admin_password_tokens (admin_user_id, token_hash, purpose, expires_at)
         VALUES (:admin_user_id, :token_hash, :purpose, DATE_ADD(NOW(), INTERVAL :ttl HOUR))'
    );
    $stmt->execute([
        'admin_user_id' => $adminUserId,
        'token_hash' => $tokenHash,
        'purpose' => $purpose,
        'ttl' => $ttlHours,
    ]);

    $frontendUrl = rtrim((string) ($config['app']['frontend_url'] ?? ''), '/');
    $link = "{$frontendUrl}/admin/reset-password?token={$token}";

    $email = build_password_set_email($adminName, $link, $purpose, $eventLabel);
    send_email($config, $adminEmail, $email['subject'], $email['html']);
}
