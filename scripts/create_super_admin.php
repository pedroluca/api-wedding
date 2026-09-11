<?php

declare(strict_types=1);

/**
 * Cria (ou redefine a senha de) um admin SUPERIOR — event_id NULL, gerencia
 * eventos e seus admins. Bootstrap de emergência: no dia a dia, admins de
 * evento são criados pelo próprio super-admin via /admin/super/events, que
 * dispara um convite por email (ver handlers/super_admins.php).
 *
 * Uso: php scripts/create_super_admin.php "Nome" "email@exemplo.com" "senha-forte"
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Disponível apenas via linha de comando.');
}

require __DIR__ . '/../src/Database.php';

[, $name, $email, $password] = $argv + [null, null, null, null];

if (!$name || !$email || !$password) {
    fwrite(STDERR, "Uso: php scripts/create_super_admin.php \"Nome\" \"email@exemplo.com\" \"senha-forte\"\n");
    exit(1);
}

if (mb_strlen($password) < 8) {
    fwrite(STDERR, "A senha precisa ter ao menos 8 caracteres.\n");
    exit(1);
}

$config = require __DIR__ . '/../config.php';
$pdo = db($config);
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('SELECT id, event_id FROM admin_users WHERE email = :email');
$stmt->execute(['email' => $email]);
$existing = $stmt->fetch();

if ($existing) {
    if ($existing['event_id'] !== null) {
        fwrite(STDERR, "Já existe um admin DE EVENTO com este email (id {$existing['id']}) — recuse-se a convertê-lo em super-admin por aqui.\n");
        exit(1);
    }

    $update = $pdo->prepare('UPDATE admin_users SET name = :name, password_hash = :hash WHERE id = :id');
    $update->execute(['name' => $name, 'hash' => $hash, 'id' => $existing['id']]);
    echo "Super-admin atualizado (id {$existing['id']}).\n";
    exit(0);
}

$insert = $pdo->prepare(
    'INSERT INTO admin_users (event_id, name, email, password_hash) VALUES (NULL, :name, :email, :hash)'
);
$insert->execute(['name' => $name, 'email' => $email, 'hash' => $hash]);
echo 'Super-admin criado (id ' . $pdo->lastInsertId() . ").\n";
