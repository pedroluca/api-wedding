<?php

declare(strict_types=1);

/**
 * GET /admin/super/events/{eventId}/admins
 * Cada admin vem com other_events: os outros eventos que a mesma conta
 * administra (editar nome/email vale para todos eles).
 */
function handle_list_event_admins(PDO $pdo, int $eventId): void
{
    ensure_event_exists($pdo, $eventId);

    $stmt = $pdo->prepare(
        'SELECT au.id, au.name, au.email, ea.created_at
         FROM event_admins ea
         INNER JOIN admin_users au ON au.id = ea.admin_user_id
         WHERE ea.event_id = :event_id
         ORDER BY ea.created_at ASC, au.id ASC'
    );
    $stmt->execute(['event_id' => $eventId]);
    $admins = $stmt->fetchAll();

    $others = $pdo->prepare(
        'SELECT ea.admin_user_id, e.id, e.event_type, e.host_name, e.host_name_secondary, e.subtitle
         FROM event_admins ea
         INNER JOIN events e ON e.id = ea.event_id
         WHERE ea.event_id <> :event_id
           AND ea.admin_user_id IN (SELECT admin_user_id FROM event_admins WHERE event_id = :members_of)
         ORDER BY e.created_at ASC'
    );
    $others->execute(['event_id' => $eventId, 'members_of' => $eventId]);
    $otherEvents = [];
    foreach ($others->fetchAll() as $row) {
        $otherEvents[(int) $row['admin_user_id']][] = [
            'id' => (int) $row['id'],
            'label' => format_event_label(
                $row['event_type'],
                $row['host_name'],
                $row['host_name_secondary'],
                $row['subtitle']
            ),
        ];
    }

    json_response(200, ['admins' => array_map(
        static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'created_at' => $row['created_at'],
            'other_events' => $otherEvents[(int) $row['id']] ?? [],
        ],
        $admins
    )]);
}

/**
 * POST /admin/super/events/{eventId}/admins
 * Body: { "name": "...", "email": "..." } — sem campo de senha: o próprio
 * convidado define a senha pelo link enviado por email. Se o email já tem
 * conta, ela só ganha este evento (sem senha nova, e o nome enviado não
 * muda o da conta) e a resposta vem com existing_account: true.
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

    $eventLabel = format_event_label(
        $event['event_type'],
        $event['host_name'],
        $event['host_name_secondary'],
        $event['subtitle']
    );

    $account = find_account_by_email($pdo, $email);
    if ($account) {
        $adminId = (int) $account['id'];
        if ($account['is_super']) {
            json_error(409, 'Este email é de um super-admin. Use outro email para o admin do evento.');
        }
        if (is_event_admin($pdo, $eventId, $adminId)) {
            json_error(409, 'Essa pessoa já é admin deste evento.');
        }

        add_event_membership($pdo, $eventId, $adminId);
        send_event_access_email($config, $account['name'], $account['email'], $eventLabel);

        json_response(201, [
            'admin' => ['id' => $adminId, 'name' => $account['name'], 'email' => $account['email']],
            'existing_account' => true,
        ]);
    }

    $adminId = create_event_admin($pdo, $eventId, $name, $email);
    issue_and_send_password_token($pdo, $config, $adminId, $name, $email, 'invite', $eventLabel);

    json_response(201, ['admin' => ['id' => $adminId, 'name' => $name, 'email' => $email], 'existing_account' => false]);
}

/**
 * PUT /admin/super/events/{eventId}/admins/{adminId}
 * Body: { "name": "...", "email": "..." } — não mexe em senha (isso
 * continua exclusivo do fluxo de convite/"esqueci senha"). Muda a conta da
 * pessoa, então vale em todos os eventos dela (a aba Admins avisa).
 */
function handle_update_event_admin(PDO $pdo, int $eventId, int $adminId): void
{
    ensure_event_exists($pdo, $eventId);

    if (!is_event_admin($pdo, $eventId, $adminId)) {
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
 * Tira a pessoa deste evento. Ela continua com os outros eventos dela; a
 * conta só é apagada se ficar sem nenhum (settle_account_events). Impede
 * remover o último admin restante do evento — sem isso, o evento ficaria
 * sem ninguém para operá-lo.
 */
function handle_delete_event_admin(PDO $pdo, int $eventId, int $adminId): void
{
    ensure_event_exists($pdo, $eventId);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM event_admins WHERE event_id = :event_id');
    $countStmt->execute(['event_id' => $eventId]);
    if ((int) $countStmt->fetchColumn() <= 1) {
        json_error(422, 'Não é possível remover o último admin do evento.');
    }

    $removed = false;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM event_admins WHERE event_id = :event_id AND admin_user_id = :admin_id');
        $stmt->execute(['event_id' => $eventId, 'admin_id' => $adminId]);
        $removed = $stmt->rowCount() > 0;
        if ($removed) {
            settle_account_events($pdo, $adminId);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_error(500, 'Não foi possível remover o admin.');
    }

    if (!$removed) {
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
 * Cria a conta de um admin de evento com senha aleatória inutilizável —
 * login só fica possível depois que ele definir a própria senha pelo link
 * de convite (admin_password_tokens, mesmo mecanismo do "esqueci senha").
 * A conta já nasce ligada ao evento (event_admins), e o admin_users.event_id
 * também é gravado, para a API anterior à 021 (ver src/Accounts.php).
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
    $adminId = (int) $pdo->lastInsertId();

    add_event_membership($pdo, $eventId, $adminId);

    return $adminId;
}

/**
 * Avisa quem já tem conta que ganhou mais um evento: entra com a mesma
 * senha de sempre, então o link é o do login (não há token).
 */
function send_event_access_email(array $config, string $name, string $email, string $eventLabel): void
{
    $frontendUrl = rtrim((string) ($config['app']['frontend_url'] ?? ''), '/');
    $message = build_event_access_email($name, "{$frontendUrl}/admin/login", $eventLabel);
    send_email($config, $email, $message['subject'], $message['html']);
}
