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
            'http://localhost:5174',
            'http://192.168.0.143:5173',
            'https://presenzo.com.br',
        ],
    ],
    'session_ttl_days' => 7,
    'mail' => [
        'api_key' => '',
        'from' => 'Presenzo Convites <contato@presenzo.com.br>',
    ],
    'app' => [
        // Origem do frontend, usada para montar os links de convite/redefinição
        // de senha enviados por email (a API não tem como inferir essa origem
        // a partir da própria requisição, diferente de public_asset_url()).
        'frontend_url' => 'http://localhost:5173',
        // Base usada por public_asset_url() quando o arquivo de uploads/ não
        // existe no disco desta instância (ex: dev local com banco copiado da
        // produção). Em produção os arquivos existem, então não tem efeito.
        // String vazia desliga o fallback.
        'assets_fallback_url' => 'https://api.presenzo.com.br',
        // Endereço público desta API, usado para montar a URL do webhook que
        // vai na preferência do Mercado Pago (a API não tem como saber o
        // próprio endereço público atrás do proxy da hospedagem).
        'api_url' => 'http://localhost:8000',
        // Quem recebe o aviso de pedido pago (e de estorno). Vazio desliga.
        'notify_email' => 'contato@presenzo.com.br',
    ],
    'mercadopago' => [
        // Access token da conta (APP_USR-...). Vazio desliga o pagamento
        // online: POST /orders responde 503 e o front esconde "Contratar".
        'access_token' => '',
        // "Assinatura secreta" do webhook (Suas integrações → Webhooks no
        // painel do MP). Vazia, a API não confere a assinatura dos avisos
        // (só em dev: o pagamento é sempre buscado no MP de qualquer forma).
        'webhook_secret' => '',
        // Testes locais apontam para um servidor falso do MP.
        'api_base' => 'https://api.mercadopago.com',
        'max_installments' => 4,
        // Prazo do pedido pendente: depois disso ele expira e a preferência
        // no MP para de aceitar pagamento.
        'order_ttl_hours' => 24,
        // Nome na fatura do cartão (até 13 caracteres).
        'statement_descriptor' => 'PRESENZO',
    ],
    'access' => [
        // Dias de acesso ao painel admin após a data do evento (event_date),
        // usado como padrão ao criar/atualizar um evento sem access_expires_at
        // explícito. Folga generosa o suficiente para cobrir toda a janela de
        // confirmações e alguns dias depois do próprio evento.
        'grace_days' => 15,
        // O mesmo para eventos Premium: o dobro do Padrão. O plano Ilimitado
        // não tem prazo (ver plan_grace_days em src/Plans.php).
        // Os dois são só reserva: o valor em vigor fica em app_settings
        // (grace_days_standard/grace_days_premium), editável no super-admin.
        'grace_days_premium' => 30,
    ],
    'limits' => [
        // Margem acima da faixa de convidados contratada antes de travar o
        // cadastro (50 → até 60). Ver guest_cap em src/Plans.php. Reserva: o
        // valor em vigor fica em app_settings, editável no super-admin.
        'guest_tolerance_percent' => 20,
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
