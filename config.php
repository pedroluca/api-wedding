<?php

declare(strict_types=1);

/**
 * Configuração da API. Os valores default abaixo servem para desenvolvimento
 * local; em produção, copie config.local.php.example para config.local.php
 * (arquivo fora do git) e ajuste as credenciais reais do banco e as origens
 * permitidas em CORS.
 */

$config = [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'wedding',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'cors' => [
        'allowed_origins' => [
            'http://localhost:5173',
            'http://192.168.0.143:5173',
            'https://wedding.pedroluca.dev.br',
        ],
    ],
    'session_ttl_days' => 7,
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

return $config;
