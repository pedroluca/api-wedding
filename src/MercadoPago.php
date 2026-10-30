<?php

declare(strict_types=1);

/**
 * Cliente do Mercado Pago (Checkout Pro), com curl direto como o Resend em
 * src/Mailer.php: sem SDK e sem composer (a API roda PHP 7.4 na Hostinger).
 *
 * Três operações: criar a preferência (a página de pagamento do MP para um
 * pedido), buscar um pagamento e conferir a assinatura de um webhook. O
 * que muda o pedido fica em handlers/orders.php.
 */

/** Pagamento online ligado? Sem access_token, o site segue só com o WhatsApp. */
function mp_enabled(array $config): bool
{
    return trim((string) ($config['mercadopago']['access_token'] ?? '')) !== '';
}

/**
 * Chamada à API do MP. Devolve [status HTTP, corpo decodificado]; status 0
 * é sem resposta (rede, timeout).
 *
 * @return array{0: int, 1: array}
 */
function mp_request(array $config, string $method, string $path, ?array $body = null, array $extraHeaders = []): array
{
    $mp = $config['mercadopago'];
    $ch = curl_init(rtrim((string) $mp['api_base'], '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge([
            'Authorization: Bearer ' . $mp['access_token'],
            'Content-Type: application/json',
        ], $extraHeaders),
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $status >= 300) {
        $detail = is_string($response) ? substr($response, 0, 500) : 'sem resposta';
        error_log("MercadoPago: {$method} {$path} falhou ({$status}): {$detail}");
    }
    $data = is_string($response) ? json_decode($response, true) : null;

    return [$status, is_array($data) ? $data : []];
}

/**
 * Cria a preferência (a página de pagamento) de um pedido: Pix e cartão em
 * até max_installments, sem boleto, válida até o pedido expirar. O cliente
 * volta para /pedido/{public_id} pagando ou não, e o MP avisa o resultado
 * em /webhooks/mercadopago.
 *
 * @param array $order linha de orders
 * @param int $expiresAt quando o pedido expira (timestamp Unix)
 * @return ?array{id: string, init_point: string} null se o MP não aceitou
 */
function mp_create_preference(array $config, array $order, string $title, int $expiresAt): ?array
{
    $mp = $config['mercadopago'];
    $frontendUrl = rtrim((string) ($config['app']['frontend_url'] ?? ''), '/');
    $apiUrl = rtrim((string) ($config['app']['api_url'] ?? ''), '/');
    $returnUrl = "{$frontendUrl}/pedido/{$order['public_id']}";
    // Instante absoluto em UTC: não depende do fuso do PHP nem do banco.
    $expiration = gmdate('Y-m-d\TH:i:s.000+00:00', $expiresAt);

    $body = [
        'items' => [[
            'id' => $order['public_id'],
            'title' => $title,
            'quantity' => 1,
            'unit_price' => (float) $order['total'],
            'currency_id' => 'BRL',
        ]],
        'payer' => ['name' => $order['customer_name'], 'email' => $order['customer_email']],
        'external_reference' => $order['public_id'],
        // source_news=webhooks: só o aviso novo (assinado), sem o IPN antigo.
        'notification_url' => "{$apiUrl}/webhooks/mercadopago?source_news=webhooks",
        'back_urls' => ['success' => $returnUrl, 'pending' => $returnUrl, 'failure' => $returnUrl],
        'payment_methods' => [
            'installments' => (int) $mp['max_installments'],
            'excluded_payment_types' => [['id' => 'ticket']],
        ],
        'expires' => true,
        'expiration_date_to' => $expiration,
        // Validade do Pix gerado (meios que não são cartão).
        'date_of_expiration' => $expiration,
        'statement_descriptor' => (string) $mp['statement_descriptor'],
    ];
    // Voltar sozinho para o site depois do pagamento aprovado. O MP recusa a
    // preferência inteira ("auto_return invalid") se a volta não for um
    // endereço público, caso do dev em http://localhost: lá a página do MP
    // mostra o botão "Voltar ao site" em vez de voltar sozinha.
    if (strpos($returnUrl, 'https://') === 0) {
        $body['auto_return'] = 'approved';
    }

    [$status, $data] = mp_request(
        $config,
        'POST',
        '/checkout/preferences',
        $body,
        ['X-Idempotency-Key: ' . $order['public_id']]
    );
    if ($status < 200 || $status >= 300 || empty($data['id']) || empty($data['init_point'])) {
        return null;
    }

    return ['id' => (string) $data['id'], 'init_point' => (string) $data['init_point']];
}

/**
 * Busca um pagamento no MP. É a única fonte que a API usa para mudar um
 * pedido: o corpo do webhook e a URL de volta só dizem qual pagamento olhar.
 *
 * @return array{0: int, 1: array} [status HTTP, pagamento]
 */
function mp_fetch_payment(array $config, string $paymentId): array
{
    if (!preg_match('/^\d{1,20}$/', $paymentId)) {
        return [404, []];
    }

    return mp_request($config, 'GET', '/v1/payments/' . $paymentId);
}

/**
 * Confere a assinatura de um webhook (cabeçalho x-signature: "ts=...,v1=...").
 * O MP assina com HMAC-SHA256 a linha "id:{data.id};request-id:{x-request-id};ts:{ts};",
 * usando a assinatura secreta do webhook; partes ausentes saem da linha.
 * O data.id vai em minúsculas (a documentação pede isso para ids com letras;
 * os de pagamento são só números).
 */
function mp_signature_valid(string $secret, string $signatureHeader, string $requestId, string $dataId): bool
{
    $parts = [];
    foreach (explode(',', $signatureHeader) as $piece) {
        [$key, $value] = explode('=', $piece, 2) + [1 => ''];
        $parts[strtolower(trim($key))] = trim($value);
    }
    $ts = $parts['ts'] ?? '';
    $hash = $parts['v1'] ?? '';
    if ($ts === '' || $hash === '') {
        return false;
    }

    $manifest = '';
    if (trim($dataId) !== '') {
        $manifest .= 'id:' . strtolower(trim($dataId)) . ';';
    }
    if (trim($requestId) !== '') {
        $manifest .= 'request-id:' . trim($requestId) . ';';
    }
    $manifest .= "ts:{$ts};";

    return hash_equals(hash_hmac('sha256', $manifest, $secret), strtolower($hash));
}
