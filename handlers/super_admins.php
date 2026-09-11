<?php

declare(strict_types=1);

/**
 * GET /admin/super/events/{eventId}/admins
 */
function handle_list_event_admins(PDO $pdo, int $eventId): void
{
    ensure_event_exists($pdo, $eventId);

    $stmt = $pdo->prepare(
        'SELECT id, name, email, created_at FROM admin_users WHERE event_id = :event_id ORDER BY created_at ASC'
    );
    $stmt->execute(['event_id' => $eventId]);

    json_response(200, ['admins' => array_map(
        static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'created_at' => $row['created_at'],
        ],
        $stmt->fetchAll()
    )]);
}

/**
 * POST /admin/super/events/{eventId}/admins
 * Body: { "name": "...", "email": "..." } — sem campo de senha: o próprio
 * convidado define a senha pelo link enviado por email.
 */
function handle_create_event_admin(PDO $pdo, array $config, int $eventId): void
{
    $event = fetch_event_row($pdo, $eventId);
    if (!$event) {
        json_error(404, 'Evento não encontrado.');
    }

    $body = json_body();
    $name = require_string($body, 'name', 150);
    $email = trim((string) ($body['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error(422, 'Informe um email válido.');
    }

    $emailTaken = $pdo->prepare('SELECT id FROM admin_users WHERE email = :email');
    $emailTaken->execute(['email' => $email]);
    if ($emailTaken->fetch()) {
        json_error(409, 'Já existe um admin cadastrado com este email.');
    }

    $adminId = create_event_admin($pdo, $eventId, $name, $email);
    $eventLabel = format_event_label($event['event_type'], $event['host_name'], $event['host_name_secondary']);
    issue_and_send_password_token($pdo, $config, $adminId, $name, $email, 'invite', $eventLabel);

    json_response(201, ['admin' => ['id' => $adminId, 'name' => $name, 'email' => $email]]);
}

/**
 * PUT /admin/super/events/{eventId}/admins/{adminId}
 * Body: { "name": "...", "email": "..." } — não mexe em senha (isso
 * continua exclusivo do fluxo de convite/"esqueci senha"). Serve, entre
 * outras coisas, para liberar o email de um admin de um evento antigo/
 * inativo sem precisar apagar o evento inteiro (a alternativa mais radical
 * é handle_delete_event, que apaga tudo).
 */
function handle_update_event_admin(PDO $pdo, int $eventId, int $adminId): void
{
    ensure_event_exists($pdo, $eventId);

    $current = $pdo->prepare('SELECT id FROM admin_users WHERE id = :id AND event_id = :event_id');
    $current->execute(['id' => $adminId, 'event_id' => $eventId]);
    if (!$current->fetch()) {
        json_error(404, 'Admin não encontrado.');
    }

    $body = json_body();
    $name = require_string($body, 'name', 150);
    $email = trim((string) ($body['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error(422, 'Informe um email válido.');
    }

    $emailTaken = $pdo->prepare('SELECT id FROM admin_users WHERE email = :email AND id != :id');
    $emailTaken->execute(['email' => $email, 'id' => $adminId]);
    if ($emailTaken->fetch()) {
        json_error(409, 'Já existe um admin cadastrado com este email.');
    }

    $pdo->prepare('UPDATE admin_users SET name = :name, email = :email WHERE id = :id')
        ->execute(['name' => $name, 'email' => $email, 'id' => $adminId]);

    json_response(200, ['admin' => ['id' => $adminId, 'name' => $name, 'email' => $email]]);
}

/**
 * DELETE /admin/super/events/{eventId}/admins/{adminId}
 * Impede remover o último admin restante do evento — sem isso, o evento
 * ficaria sem ninguém para operá-lo.
 */
function handle_delete_event_admin(PDO $pdo, int $eventId, int $adminId): void
{
    ensure_event_exists($pdo, $eventId);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE event_id = :event_id');
    $countStmt->execute(['event_id' => $eventId]);
    if ((int) $countStmt->fetchColumn() <= 1) {
        json_error(422, 'Não é possível remover o último admin do evento.');
    }

    $stmt = $pdo->prepare('DELETE FROM admin_users WHERE id = :id AND event_id = :event_id');
    $stmt->execute(['id' => $adminId, 'event_id' => $eventId]);

    if ($stmt->rowCount() === 0) {
        json_error(404, 'Admin não encontrado.');
    }

    json_response(200, ['ok' => true]);
}

function ensure_event_exists(PDO $pdo, int $eventId): void
{
    $stmt = $pdo->prepare('SELECT id FROM events WHERE id = :id');
    $stmt->execute(['id' => $eventId]);
    if (!$stmt->fetch()) {
        json_error(404, 'Evento não encontrado.');
    }
}

/**
 * Cria um admin de evento com senha aleatória inutilizável — login só fica
 * possível depois que ele definir a própria senha pelo link de convite
 * (admin_password_tokens, mesmo mecanismo do "esqueci senha").
 * Reaproveitada por handlers/super_events.php ao criar o primeiro admin de
 * um evento novo.
 */
function create_event_admin(PDO $pdo, int $eventId, string $name, string $email): int
{
    $unusablePassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        'INSERT INTO admin_users (event_id, name, email, password_hash)
         VALUES (:event_id, :name, :email, :password_hash)'
    );
    $stmt->execute([
        'event_id' => $eventId,
        'name' => $name,
        'email' => $email,
        'password_hash' => $unusablePassword,
    ]);

    return (int) $pdo->lastInsertId();
}
