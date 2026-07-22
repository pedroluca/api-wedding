<?php

declare(strict_types=1);

/**
 * Cria (ou atualiza a senha de) um usuário admin.
 * Uso: php scripts/create_admin.php "Nome" "email@exemplo.com" "senha-forte"
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Disponível apenas via linha de comando.');
}

require __DIR__ . '/../src/Database.php';

[, $name, $email, $password] = $argv + [null, null, null, null];

if (!$name || !$email || !$password) {
    fwrite(STDERR, "Uso: php scripts/create_admin.php \"Nome\" \"email@exemplo.com\" \"senha-forte\"\n");
    exit(1);
}

if (mb_strlen($password) < 8) {
    fwrite(STDERR, "A senha precisa ter ao menos 8 caracteres.\n");
    exit(1);
}

$config = require __DIR__ . '/../config.php';
$pdo = db($config);
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('SELECT id FROM admin_users WHERE email = :email');
$stmt->execute(['email' => $email]);
$existing = $stmt->fetch();

if ($existing) {
    $update = $pdo->prepare('UPDATE admin_users SET name = :name, password_hash = :hash WHERE id = :id');
    $update->execute(['name' => $name, 'hash' => $hash, 'id' => $existing['id']]);
    echo "Usuário atualizado (id {$existing['id']}).\n";
    exit(0);
}

$insert = $pdo->prepare('INSERT INTO admin_users (name, email, password_hash) VALUES (:name, :email, :hash)');
$insert->execute(['name' => $name, 'email' => $email, 'hash' => $hash]);
echo 'Usuário criado (id ' . $pdo->lastInsertId() . ").\n";
