<?php

declare(strict_types=1);

/**
 * GET /pricing
 * Tabela de preços em vigor: valores fixos (base por tipo de evento e
 * adicionais), faixas de convidados e parâmetros dos planos. Pública: monta
 * as opções do simulador da home e da calculadora do super-admin. checkout
 * diz se dá pra contratar pelo site (pagamento online ligado).
 */
function handle_get_pricing(PDO $pdo, array $config): void
{
    json_response(200, pricing_payload($pdo) + [
        'checkout' => [
            'enabled' => mp_enabled($config),
            'max_installments' => (int) ($config['mercadopago']['max_installments'] ?? 1),
        ],
    ]);
}

function pricing_payload(PDO $pdo): array
{
    return [
        'prices' => array_values(fetch_price_items($pdo)),
        'tiers' => array_map(
            static fn (array $tier): array => array_diff_key($tier, ['guest_limit' => true]),
            fetch_guest_tiers($pdo)
        ),
        'settings' => current_app_settings(),
    ];
}

/**
 * POST /pricing/quote
 * Body: { event_type, guest_tier_id, gifts, premium, extra_admins, coupon? }
 * Devolve o valor e de onde ele vem (ver compute_quote em src/Pricing.php).
 */
function handle_quote(PDO $pdo): void
{
    json_response(200, ['quote' => compute_quote($pdo, read_quote_choice($pdo, json_body()))]);
}

/**
 * Valida a escolha do orçamento (tipo, faixa, adicionais e cupom) no
 * formato que compute_quote() espera.
 */
function read_quote_choice(PDO $pdo, array $body): array
{
    $tierId = filter_var($body['guest_tier_id'] ?? null, FILTER_VALIDATE_INT);
    $tier = null;
    foreach (fetch_guest_tiers($pdo) as $candidate) {
        if ($candidate['id'] === $tierId) {
            $tier = $candidate;
        }
    }
    if ($tier === null) {
        json_error(422, 'Faixa de convidados inválida.');
    }

    $extraAdmins = filter_var($body['extra_admins'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
    if ($extraAdmins === false) {
        json_error(422, 'Quantidade de admins extras inválida.');
    }

    $coupon = trim((string) ($body['coupon'] ?? ''));
    // Evento cujo orçamento está sendo recalculado (calculadora do super):
    // não conta contra o limite de usos do próprio cupom.
    $eventId = filter_var($body['event_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return [
        'event_type' => read_event_type($body),
        'tier' => $tier,
        'gifts' => filter_var($body['gifts'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'premium' => filter_var($body['premium'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'extra_admins' => $extraAdmins,
        'coupon' => $coupon !== '' ? $coupon : null,
        'event_id' => $eventId !== false ? $eventId : null,
    ];
}

/**
 * Orçamento aplicado na venda (campo "quote" na criação e na edição do
 * evento pelo super-admin): a mesma escolha de POST /pricing/quote, em
 * JSON (no multipart vem como texto). Recalculado aqui pela tabela atual,
 * nunca aceito pronto do front. Só mexe quando vem no corpo; null limpa.
 *
 * price_breakdown guarda a escolha e os itens como estavam no dia: mudar a
 * tabela depois não muda o que foi vendido.
 *
 * @return array{price_breakdown?: ?string, coupon_code?: ?string}
 */
function read_sent_quote(PDO $pdo, array $body, ?int $eventId = null): array
{
    if (!array_key_exists('quote', $body)) {
        return [];
    }

    $choice = $body['quote'];
    if (is_string($choice)) {
        $choice = trim($choice) === '' ? null : json_decode($choice, true);
    }
    if ($choice === null) {
        return ['price_breakdown' => null, 'coupon_code' => null];
    }
    if (!is_array($choice)) {
        json_error(422, 'Orçamento inválido.');
    }

    // O evento salvo é sempre o da URL (na criação, nenhum), nunca o do corpo.
    $parsed = ['event_id' => $eventId] + read_quote_choice($pdo, $choice);
    $quote = compute_quote($pdo, $parsed);

    return [
        'price_breakdown' => json_encode(quote_breakdown($parsed, $quote), JSON_UNESCAPED_UNICODE),
        'coupon_code' => $quote['coupon']['code'] ?? null,
    ];
}

/**
 * O orçamento como foi vendido (events.price_breakdown e orders.price_breakdown):
 * a escolha e os itens do dia. Mudar a tabela depois não muda o que foi vendido.
 *
 * @param array $parsed escolha de read_quote_choice()
 * @param array $quote resultado de compute_quote()
 */
function quote_breakdown(array $parsed, array $quote): array
{
    return [
        'choice' => [
            'event_type' => $parsed['event_type'],
            'max_guests' => $parsed['tier']['max_guests'],
            'gifts' => $parsed['gifts'],
            'premium' => $parsed['premium'],
            'extra_admins' => $parsed['extra_admins'],
            'coupon' => $quote['coupon']['code'] ?? null,
        ],
        'items' => $quote['items'],
        'subtotal' => $quote['subtotal'],
        'discount' => $quote['discount'],
        'total' => $quote['total'],
        'quoted_at' => date('Y-m-d H:i:s'),
    ];
}

/**
 * PUT /admin/super/pricing
 * Body: { prices: { item_key: valor }, tiers: [{ max_guests, amount }], settings: { chave: valor } }
 * Salva a tela "Preços e planos" de uma vez. As faixas são substituídas
 * pelas enviadas (nada guarda o id delas: o evento vendido guarda o limite).
 * Eventos já vendidos não mudam: guardam o valor cobrado e o limite.
 */
function handle_update_pricing(PDO $pdo, array $config): void
{
    $body = json_body();
    $known = fetch_price_items($pdo);

    $prices = [];
    foreach ((array) ($body['prices'] ?? []) as $key => $value) {
        if (!array_key_exists($key, $known)) {
            json_error(422, "Item de preço desconhecido: {$key}.");
        }
        $prices[$key] = read_money($value, $known[$key]['label']);
    }

    $tiers = [];
    foreach ((array) ($body['tiers'] ?? []) as $tier) {
        $max = filter_var($tier['max_guests'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if ($max === false) {
            json_error(422, 'Cada faixa precisa de uma quantidade de convidados a partir de 1.');
        }
        if (isset($tiers[$max])) {
            json_error(422, "Há duas faixas de {$max} convidados.");
        }
        $tiers[$max] = read_money($tier['amount'] ?? null, "faixa de {$max} convidados");
    }
    if ($tiers === []) {
        json_error(422, 'Cadastre ao menos uma faixa de convidados.');
    }

    $settings = [];
    foreach ((array) ($body['settings'] ?? []) as $key => $value) {
        if (!array_key_exists($key, APP_SETTINGS)) {
            json_error(422, "Parâmetro desconhecido: {$key}.");
        }
        [, $min, $max] = APP_SETTINGS[$key];
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($int === false) {
            json_error(422, "Valor inválido: {$key} (de {$min} a {$max}).");
        }
        $settings[$key] = $int;
    }

    $pdo->beginTransaction();
    try {
        $updatePrice = $pdo->prepare('UPDATE price_items SET amount = :amount WHERE item_key = :key');
        foreach ($prices as $key => $amount) {
            $updatePrice->execute(['amount' => $amount, 'key' => $key]);
        }

        $pdo->exec('DELETE FROM guest_tiers');
        $insertTier = $pdo->prepare('INSERT INTO guest_tiers (max_guests, amount) VALUES (:max, :amount)');
        foreach ($tiers as $max => $amount) {
            $insertTier->execute(['max' => $max, 'amount' => $amount]);
        }

        $upsertSetting = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        foreach ($settings as $key => $value) {
            $upsertSetting->execute(['key' => $key, 'value' => (string) $value]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_error(500, 'Não foi possível salvar os preços.');
    }

    load_app_settings($pdo, $config);
    json_response(200, pricing_payload($pdo));
}

/**
 * Valor em reais, de 0 a 1 milhão.
 *
 * @param mixed $value número ou texto (sem o tipo mixed na assinatura, que só existe no PHP 8)
 */
function read_money($value, string $label): float
{
    $amount = filter_var(is_string($value) ? trim($value) : $value, FILTER_VALIDATE_FLOAT);
    if ($amount === false || $amount < 0 || $amount > 1000000) {
        json_error(422, "Valor inválido: {$label}.");
    }

    return round($amount, 2);
}
