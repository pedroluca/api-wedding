<?php

declare(strict_types=1);

/**
 * POST /admin/login
 * Body: { "email": "...", "password": "..." }
 * A sessão já nasce no primeiro evento da lista da conta (ver
 * sort_account_events()); o super nasce sem evento. A resposta traz os
 * eventos, para o front mostrar "Seus eventos" a quem tem mais de um.
 */
function handle_admin_login(PDO $pdo, array $config): void
{
    $body = json_body();
    $email = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($email === '' || $password === '') {
        json_error(422, 'Informe email e senha.');
    }

    $stmt = $pdo->prepare('SELECT id, is_super, name, email, password_hash FROM admin_users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        json_error(401, 'Email ou senha inválidos.');
    }

    $isSuper = (bool) $admin['is_super'];
    $events = $isSuper ? [] : fetch_account_events($pdo, (int) $admin['id']);
    if (!$isSuper && $events === []) {
        json_error(403, 'Esta conta não administra nenhum evento. Fale com o suporte.');
    }
    $activeEventId = $isSuper ? null : (int) $events[0]['id'];

    // limpeza oportunista de sessões expiradas
    $pdo->exec('DELETE FROM admin_sessions WHERE expires_at < NOW()');

    $token = generate_token();
    $ttlDays = (int) ($config['session_ttl_days'] ?? 7);

    $insert = $pdo->prepare(
        'INSERT INTO admin_sessions (admin_user_id, event_id, token, expires_at)
         VALUES (:admin_user_id, :event_id, :token, DATE_ADD(NOW(), INTERVAL :ttl DAY))'
    );
    $insert->execute([
        'admin_user_id' => $admin['id'],
        'event_id' => $activeEventId,
        'token' => $token,
        'ttl' => $ttlDays,
    ]);

    json_response(200, ['token' => $token] + account_session_payload($admin, $activeEventId, $events));
}

/**
 * GET /admin/me
 * A conta, o evento ativo da sessão e a lista de eventos atualizada (um
 * evento novo ligado à conta aparece aqui sem precisar entrar de novo).
 */
function handle_admin_me(PDO $pdo, array $admin): void
{
    $events = $admin['is_super'] ? [] : fetch_account_events($pdo, $admin['id']);
    json_response(200, account_session_payload($admin, $admin['event_id'], $events));
}

/**
 * POST /admin/session/event
 * Body: { "event_id": 12 }
 * Troca o evento ativo da sessão, só entre os eventos da conta. Não exige
 * acesso aberto: a lista mostra os encerrados, e o painel avisa ao abrir.
 */
function handle_switch_session_event(PDO $pdo, array $admin): void
{
    if ($admin['is_super']) {
        json_error(403, 'O super-admin não tem evento ativo. Use "entrar como admin".');
    }

    $eventId = (int) (json_body()['event_id'] ?? 0);
    $events = fetch_account_events($pdo, $admin['id']);
    $ids = array_map(static fn (array $event): int => (int) $event['id'], $events);
    if (!in_array($eventId, $ids, true)) {
        json_error(404, 'Evento não encontrado.');
    }

    $pdo->prepare('UPDATE admin_sessions SET event_id = :event_id WHERE token = :token')
        ->execute(['event_id' => $eventId, 'token' => $admin['_token']]);

    json_response(200, account_session_payload($admin, $eventId, $events));
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
