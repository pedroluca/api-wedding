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
            'https://presenzo.com.br',
        ],
    ],
    'session_ttl_days' => 7,
    'mail' => [
        'api_key' => '',
        'from' => 'Presenzo <contato@presenzo.com.br>',
    ],
    'app' => [
        // Origem do frontend, usada para montar os links de convite/redefinição
        // de senha enviados por email (a API não tem como inferir essa origem
        // a partir da própria requisição, diferente de public_asset_url()).
        'frontend_url' => 'http://localhost:5173',
    ],
    'access' => [
        // Dias de acesso ao painel admin após a data do evento (event_date),
        // usado como padrão ao criar/atualizar um evento sem access_expires_at
        // explícito. Folga generosa o suficiente para cobrir toda a janela de
        // confirmações e alguns dias depois do próprio evento.
        'grace_days' => 15,
    ],
    'password_token_ttl_hours' => [
        'invite' => 168,
        'reset' => 1,
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

return $config;
