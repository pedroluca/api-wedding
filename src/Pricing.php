<?php

declare(strict_types=1);

/**
 * Parâmetros editáveis em "Preços e planos" no super-admin (tabela
 * app_settings): chave => [padrão, mínimo, máximo].
 */
const APP_SETTINGS = [
    // margem acima da faixa de convidados antes de travar (50 → 60)
    'guest_tolerance_percent' => [20, 0, 100],
    // admins que todo evento já tem; cada um a mais é o adicional extra_admin
    'included_admins' => [2, 1, 50],
    // dias de acesso ao painel depois da data do evento, por plano
    'grace_days_standard' => [15, 0, 3650],
    'grace_days_premium' => [30, 0, 3650],
];

/** Valores carregados nesta requisição (load_app_settings); vazio = padrões. */
function app_settings(?array $replace = null): array
{
    static $settings = [];
    if ($replace !== null) {
        $settings = $replace;
    }

    return $settings;
}

function app_setting(string $key): int
{
    return app_settings()[$key] ?? APP_SETTINGS[$key][0];
}

/**
 * Lê app_settings uma vez por requisição (index.php), por cima do config.php.
 * Sem a tabela (API no ar antes da migração 016), seguem valendo o config e
 * os padrões, pra nunca derrubar os convites por causa disso.
 */
function load_app_settings(PDO $pdo, array $config): void
{
    $settings = array_filter([
        'guest_tolerance_percent' => $config['limits']['guest_tolerance_percent'] ?? null,
        'grace_days_standard' => $config['access']['grace_days'] ?? null,
        'grace_days_premium' => $config['access']['grace_days_premium'] ?? null,
    ], static fn ($value): bool => $value !== null);

    try {
        $rows = $pdo->query('SELECT setting_key, value FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        $rows = [];
    }
    foreach ($rows as $key => $value) {
        if (array_key_exists($key, APP_SETTINGS) && is_numeric($value)) {
            $settings[$key] = $value;
        }
    }

    app_settings(array_map('intval', $settings));
}

/** Todos os parâmetros com o valor em vigor. */
function current_app_settings(): array
{
    $settings = [];
    foreach (array_keys(APP_SETTINGS) as $key) {
        $settings[$key] = app_setting($key);
    }

    return $settings;
}

/**
 * Valores fixos (base por tipo de evento e adicionais), na ordem da tela.
 *
 * @return array<string, array{key:string,label:string,amount:float}> item_key => item
 */
function fetch_price_items(PDO $pdo): array
{
    $order = ['base_wedding', 'base_birthday', 'base_graduation', 'base_other', 'gifts', 'extra_admin', 'premium'];
    $items = [];
    foreach ($pdo->query('SELECT item_key, label, amount FROM price_items')->fetchAll() as $row) {
        $items[$row['item_key']] = [
            'key' => $row['item_key'],
            'label' => $row['label'],
            'amount' => (float) $row['amount'],
        ];
    }
    uksort($items, static fn (string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

    return $items;
}

/**
 * Faixas de convidados em ordem crescente. A maior é aberta: cobre qualquer
 * quantidade acima ("500+") e o evento fica sem limite de convidados.
 *
 * @return list<array{id:int,max_guests:int,amount:float,open_ended:bool,label:string,guest_limit:?int}>
 */
function fetch_guest_tiers(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, max_guests, amount FROM guest_tiers ORDER BY max_guests ASC')->fetchAll();
    $last = count($rows) - 1;

    return array_map(static function (array $row, int $index) use ($last): array {
        $max = (int) $row['max_guests'];
        $openEnded = $index === $last;

        return [
            'id' => (int) $row['id'],
            'max_guests' => $max,
            'amount' => (float) $row['amount'],
            'open_ended' => $openEnded,
            'label' => $openEnded ? "{$max}+ convidados" : "Até {$max} convidados",
            // o que vai pra events.guest_limit ao vender esta faixa
            'guest_limit' => $openEnded ? null : $max,
        ];
    }, $rows, array_keys($rows));
}

/**
 * Cupom pelo código, se puder ser usado hoje.
 *
 * @return array{0: ?array, 1: ?string} [cupom, motivo de não valer]
 */
function find_usable_coupon(PDO $pdo, string $code): array
{
    $stmt = $pdo->prepare(
        'SELECT id, code, discount_type, discount_value, valid_until, active, notes, created_at
         FROM coupons WHERE code = :code'
    );
    $stmt->execute(['code' => normalize_coupon_code($code)]);
    $coupon = $stmt->fetch();

    if (!$coupon || !$coupon['active']) {
        return [null, 'Cupom não encontrado.'];
    }
    if ($coupon['valid_until'] !== null && $coupon['valid_until'] < date('Y-m-d')) {
        return [null, 'Este cupom venceu em ' . date('d/m/Y', strtotime($coupon['valid_until'])) . '.'];
    }

    return [$coupon, null];
}

function normalize_coupon_code(string $code): string
{
    return strtoupper(trim($code));
}

/**
 * Orçamento: base do tipo de evento + faixa de convidados + adicionais, menos
 * o cupom. É a única fonte do preço: o simulador público e a calculadora do
 * super chamam POST /pricing/quote e nunca somam sozinhos.
 *
 * Um cupom inválido não quebra o orçamento: não desconta e volta em
 * coupon_error. contract traz o que o evento recebe ao fechar esta venda.
 *
 * @param array{event_type:string,tier:array,gifts:bool,premium:bool,extra_admins:int,coupon:?string} $choice
 */
function compute_quote(PDO $pdo, array $choice): array
{
    $prices = fetch_price_items($pdo);
    $amount = static fn (string $key): float => $prices[$key]['amount'] ?? 0.0;
    $label = static fn (string $key, string $fallback): string => $prices[$key]['label'] ?? $fallback;

    $baseKey = "base_{$choice['event_type']}";
    $items = [
        ['label' => $label($baseKey, 'Evento'), 'amount' => $amount($baseKey)],
        ['label' => $choice['tier']['label'], 'amount' => $choice['tier']['amount']],
    ];
    if ($choice['gifts']) {
        $items[] = ['label' => $label('gifts', 'Lista de presentes e Pix'), 'amount' => $amount('gifts')];
    }
    if ($choice['extra_admins'] > 0) {
        $count = $choice['extra_admins'];
        $items[] = [
            'label' => $count === 1 ? '1 admin extra' : "{$count} admins extras",
            'amount' => round($count * $amount('extra_admin'), 2),
        ];
    }
    if ($choice['premium']) {
        $items[] = ['label' => $label('premium', 'Premium'), 'amount' => $amount('premium')];
    }

    $subtotal = round(array_sum(array_column($items, 'amount')), 2);
    $discount = 0.0;
    $coupon = null;
    $couponError = null;

    if ($choice['coupon'] !== null) {
        [$coupon, $couponError] = find_usable_coupon($pdo, $choice['coupon']);
    }
    if ($coupon !== null) {
        $value = (float) $coupon['discount_value'];
        $isPercent = $coupon['discount_type'] === 'percent';
        $discount = min($subtotal, round($isPercent ? $subtotal * $value / 100 : $value, 2));
        $items[] = [
            'label' => "Cupom {$coupon['code']}" . ($isPercent ? ' (' . format_percent($value) . ')' : ''),
            'amount' => -$discount,
        ];
    }

    return [
        'items' => $items,
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total' => round($subtotal - $discount, 2),
        'coupon' => $coupon !== null ? format_coupon($coupon) : null,
        'coupon_error' => $couponError,
        'contract' => [
            'plan' => $choice['premium'] ? 'premium' : 'standard',
            'gifts_enabled' => $choice['gifts'],
            'guest_limit' => $choice['tier']['guest_limit'],
            'admin_limit' => app_setting('included_admins') + $choice['extra_admins'],
        ],
    ];
}

/** 20 → "20%", 12.5 → "12,5%" */
function format_percent(float $value): string
{
    return str_replace('.', ',', rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.')) . '%';
}

function format_coupon(array $coupon): array
{
    return [
        'id' => (int) $coupon['id'],
        'code' => $coupon['code'],
        'discount_type' => $coupon['discount_type'],
        'discount_value' => (float) $coupon['discount_value'],
        'valid_until' => $coupon['valid_until'],
        'active' => (bool) $coupon['active'],
        'notes' => $coupon['notes'],
        'created_at' => $coupon['created_at'],
    ] + (isset($coupon['uses']) ? ['uses' => (int) $coupon['uses']] : []);
}
