<?php

declare(strict_types=1);

function db(array $config): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $db = $config['db'];
        // port é opcional (ex: MariaDB local na 3307 ao lado de um MySQL na
        // 3306). Com porta diferente da padrão, use host 127.0.0.1: com
        // "localhost" o driver pode ir por socket/pipe e ignorar a porta.
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            (int) ($db['port'] ?? 3306),
            $db['name'],
            $db['charset'] ?? 'utf8mb4'
        );

        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}
