<?php

declare(strict_types=1);

/**
 * Colunas do cupom no super-admin, com quantos eventos foram vendidos com ele
 * (events.coupon_code). Trocar o código de um cupom zera essa contagem.
 * O COLLATE explícito deixa a comparação valer mesmo que events e coupons
 * tenham collations diferentes (sem ele: "Illegal mix of collations").
 */
const COUPON_COLUMNS = 'id, code, discount_type, discount_value, valid_until, max_uses, active, notes, created_at,
    (SELECT COUNT(*) FROM events WHERE events.coupon_code = coupons.code COLLATE utf8mb4_general_ci) AS uses';

/**
 * GET /admin/super/coupons
 * Todos os cupons, os mais novos primeiro.
 */
function handle_list_coupons(PDO $pdo): void
{
    $rows = $pdo->query(
        'SELECT ' . COUPON_COLUMNS . '
         FROM coupons
         ORDER BY created_at DESC, id DESC'
    )->fetchAll();

    json_response(200, ['coupons' => array_map('format_coupon', $rows)]);
}

/**
 * POST /admin/super/coupons
 * Body: { code, discount_type: "percent"|"amount", discount_value, valid_until?, max_uses?, active?, notes? }
 */
function handle_create_coupon(PDO $pdo): void
{
    $fields = read_coupon_fields(json_body());
    ensure_coupon_code_free($pdo, $fields['code']);

    $pdo->prepare(
        'INSERT INTO coupons (code, discount_type, discount_value, valid_until, max_uses, active, notes)
         VALUES (:code, :discount_type, :discount_value, :valid_until, :max_uses, :active, :notes)'
    )->execute($fields);

    json_response(201, ['coupon' => fetch_coupon($pdo, (int) $pdo->lastInsertId())]);
}

/**
 * PUT /admin/super/coupons/{id}
 * Mesmo corpo da criação. Mudar o código não afeta orçamentos já fechados.
 */
function handle_update_coupon(PDO $pdo, int $id): void
{
    if (!fetch_coupon($pdo, $id)) {
        json_error(404, 'Cupom não encontrado.');
    }

    $fields = read_coupon_fields(json_body());
    ensure_coupon_code_free($pdo, $fields['code'], $id);

    $pdo->prepare(
        'UPDATE coupons
         SET code = :code, discount_type = :discount_type, discount_value = :discount_value,
             valid_until = :valid_until, max_uses = :max_uses, active = :active, notes = :notes
         WHERE id = :id'
    )->execute($fields + ['id' => $id]);

    json_response(200, ['coupon' => fetch_coupon($pdo, $id)]);
}

/**
 * DELETE /admin/super/coupons/{id}
 */
function handle_delete_coupon(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('DELETE FROM coupons WHERE id = :id');
    $stmt->execute(['id' => $id]);
    if ($stmt->rowCount() === 0) {
        json_error(404, 'Cupom não encontrado.');
    }

    json_response(200, ['ok' => true]);
}

function fetch_coupon(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT ' . COUPON_COLUMNS . ' FROM coupons WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ? format_coupon($row) : null;
}

function ensure_coupon_code_free(PDO $pdo, string $code, ?int $exceptId = null): void
{
    $stmt = $pdo->prepare('SELECT id FROM coupons WHERE code = :code AND id <> :id');
    $stmt->execute(['code' => $code, 'id' => $exceptId ?? 0]);
    if ($stmt->fetch()) {
        json_error(409, "Já existe um cupom {$code}.");
    }
}

/**
 * Valida o corpo do cupom. O código fica em maiúsculas (o convidado pode
 * digitar de qualquer jeito). Desconto em %: de 0,01 a 100. Em R$: a partir
 * de 0,01 (no orçamento, nunca passa do valor total).
 */
function read_coupon_fields(array $body): array
{
    $code = normalize_coupon_code((string) ($body['code'] ?? ''));
    if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
        json_error(422, 'Código do cupom: use de 3 a 40 letras sem acento, números, - ou _.');
    }

    $type = (string) ($body['discount_type'] ?? '');
    if (!in_array($type, ['percent', 'amount'], true)) {
        json_error(422, 'Tipo de desconto inválido.');
    }

    $value = filter_var($body['discount_value'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($value === false || $value <= 0 || ($type === 'percent' && $value > 100) || $value > 1000000) {
        json_error(422, $type === 'percent' ? 'O desconto em % vai de 0,01 a 100.' : 'Informe o valor do desconto em reais.');
    }

    return [
        'code' => $code,
        'discount_type' => $type,
        'discount_value' => round($value, 2),
        'valid_until' => optional_date($body, 'valid_until'),
        // quantos eventos podem ser vendidos com ele; em branco = sem limite
        'max_uses' => optional_limit($body, 'max_uses'),
        'active' => array_key_exists('active', $body) ? read_flag($body, 'active') : 1,
        'notes' => optional_string($body, 'notes', 255),
    ];
}
