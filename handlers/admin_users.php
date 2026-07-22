<?php

declare(strict_types=1);

/**
 * GET /admin/users
 */
function handle_list_admin_users(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT id, name, email, created_at FROM admin_users ORDER BY name ASC');
    json_response(200, ['users' => $stmt->fetchAll()]);
}

/**
 * POST /admin/users
 * Body: { "name": "...", "email": "...", "password": "..." }
 */
function handle_create_admin_user(PDO $pdo): void
{
    $body = json_body();
    $name = require_string($body, 'name', 150);
    $email = require_string($body, 'email', 190);
    $password = (string) ($body['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error(422, 'Email inválido.');
    }
    if (mb_strlen($password) < 8) {
        json_error(422, 'A senha precisa ter ao menos 8 caracteres.');
    }

    $exists = $pdo->prepare('SELECT id FROM admin_users WHERE email = :email');
    $exists->execute(['email' => $email]);
    if ($exists->fetch()) {
        json_error(409, 'Já existe um usuário com este email.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO admin_users (name, email, password_hash) VALUES (:name, :email, :password_hash)'
    );
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    json_response(201, [
        'user' => [
            'id' => (int) $pdo->lastInsertId(),
            'name' => $name,
            'email' => $email,
        ],
    ]);
}

/**
 * DELETE /admin/users/{id}
 */
function handle_delete_admin_user(PDO $pdo, int $id, array $currentAdmin): void
{
    if ($id === (int) $currentAdmin['id']) {
        json_error(400, 'Você não pode remover o seu próprio usuário.');
    }

    $count = (int) $pdo->query('SELECT COUNT(*) AS total FROM admin_users')->fetch()['total'];
    if ($count <= 1) {
        json_error(400, 'É necessário manter ao menos um usuário admin.');
    }

    $stmt = $pdo->prepare('DELETE FROM admin_users WHERE id = :id');
    $stmt->execute(['id' => $id]);

    if ($stmt->rowCount() === 0) {
        json_error(404, 'Usuário não encontrado.');
    }

    json_response(200, ['ok' => true]);
}
