<?php

declare(strict_types=1);

/**
 * GET /admin/gifts
 * Lista todos os presentes (inclusive os já totalmente presenteados) com
 * o histórico de quem presenteou cada um, para o admin acompanhar.
 */
function handle_list_gifts(PDO $pdo, int $eventId): void
{
    $stmt = $pdo->prepare(
        'SELECT id, name, description, image_path, suggested_amount, quantity, created_at
         FROM gifts
         WHERE event_id = :event_id
         ORDER BY created_at DESC'
    );
    $stmt->execute(['event_id' => $eventId]);
    $gifts = $stmt->fetchAll();

    $claimsStmt = $pdo->prepare(
        'SELECT gc.id, gc.message, gc.created_at, g.name AS guest_name
         FROM gift_claims gc
         INNER JOIN guests g ON g.id = gc.guest_id
         WHERE gc.gift_id = :gift_id
         ORDER BY gc.created_at ASC'
    );

    $result = [];
    foreach ($gifts as $gift) {
        $claimsStmt->execute(['gift_id' => $gift['id']]);
        $claims = $claimsStmt->fetchAll();

        $result[] = [
            'id' => (int) $gift['id'],
            'name' => $gift['name'],
            'description' => $gift['description'],
            'image_url' => public_asset_url($gift['image_path']),
            'suggested_amount' => (float) $gift['suggested_amount'],
            'quantity' => (int) $gift['quantity'],
            'claimed_count' => count($claims),
            'remaining' => max(0, (int) $gift['quantity'] - count($claims)),
            'created_at' => $gift['created_at'],
            'claims' => array_map(
                static fn (array $c): array => [
                    'id' => (int) $c['id'],
                    'guest_name' => $c['guest_name'],
                    'message' => $c['message'],
                    'created_at' => $c['created_at'],
                ],
                $claims
            ),
        ];
    }

    json_response(200, ['gifts' => $result]);
}

/**
 * POST /admin/gifts
 * multipart/form-data: name, description?, suggested_amount, quantity, image?
 */
function handle_create_gift(PDO $pdo, int $eventId): void
{
    [$name, $description, $amount, $quantity] = read_gift_fields($_POST);
    $imagePath = has_uploaded_file('image') ? save_uploaded_image($_FILES['image'], 'gifts') : null;

    $stmt = $pdo->prepare(
        'INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
         VALUES (:event_id, :name, :description, :image_path, :suggested_amount, :quantity)'
    );
    $stmt->execute([
        'event_id' => $eventId,
        'name' => $name,
        'description' => $description,
        'image_path' => $imagePath,
        'suggested_amount' => $amount,
        'quantity' => $quantity,
    ]);

    json_response(201, [
        'gift' => [
            'id' => (int) $pdo->lastInsertId(),
            'name' => $name,
            'description' => $description,
            'image_url' => public_asset_url($imagePath),
            'suggested_amount' => $amount,
            'quantity' => $quantity,
            'claimed_count' => 0,
            'remaining' => $quantity,
            'claims' => [],
        ],
    ]);
}

/**
 * POST /admin/gifts/{id}
 * Atualização via POST (em vez de PUT): o PHP só popula $_FILES em
 * requisições multipart enviadas via POST, e precisamos disso para
 * permitir trocar a imagem do presente.
 */
function handle_update_gift(PDO $pdo, int $eventId, int $id): void
{
    $current = $pdo->prepare('SELECT image_path FROM gifts WHERE id = :id AND event_id = :event_id');
    $current->execute(['id' => $id, 'event_id' => $eventId]);
    $existing = $current->fetch();
    if (!$existing) {
        json_error(404, 'Presente não encontrado.');
    }

    [$name, $description, $amount, $quantity] = read_gift_fields($_POST);

    $imagePath = $existing['image_path'];
    if (has_uploaded_file('image')) {
        $imagePath = save_uploaded_image($_FILES['image'], 'gifts');
        delete_uploaded_file($existing['image_path']);
    }

    $stmt = $pdo->prepare(
        'UPDATE gifts
         SET name = :name, description = :description, image_path = :image_path,
             suggested_amount = :suggested_amount, quantity = :quantity
         WHERE id = :id AND event_id = :event_id'
    );
    $stmt->execute([
        'name' => $name,
        'description' => $description,
        'image_path' => $imagePath,
        'suggested_amount' => $amount,
        'quantity' => $quantity,
        'id' => $id,
        'event_id' => $eventId,
    ]);

    json_response(200, ['ok' => true]);
}

/**
 * DELETE /admin/gifts/{id}
 */
function handle_delete_gift(PDO $pdo, int $eventId, int $id): void
{
    $current = $pdo->prepare('SELECT image_path FROM gifts WHERE id = :id AND event_id = :event_id');
    $current->execute(['id' => $id, 'event_id' => $eventId]);
    $existing = $current->fetch();
    if (!$existing) {
        json_error(404, 'Presente não encontrado.');
    }

    $pdo->prepare('DELETE FROM gifts WHERE id = :id AND event_id = :event_id')
        ->execute(['id' => $id, 'event_id' => $eventId]);
    delete_uploaded_file($existing['image_path']);

    json_response(200, ['ok' => true]);
}

/**
 * @return array{0:string,1:?string,2:float,3:int}
 */
function read_gift_fields(array $body): array
{
    $name = require_string($body, 'name', 150);

    $description = trim((string) ($body['description'] ?? ''));
    if ($description !== '' && mb_strlen($description) > 500) {
        json_error(422, 'Descrição muito longa.');
    }

    $amount = filter_var($body['suggested_amount'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($amount === false || $amount <= 0) {
        json_error(422, 'Informe um valor sugerido válido.');
    }

    $quantity = filter_var($body['quantity'] ?? null, FILTER_VALIDATE_INT);
    if ($quantity === false || $quantity < 1) {
        json_error(422, 'Informe uma quantidade válida (mínimo 1).');
    }

    return [$name, $description === '' ? null : $description, $amount, $quantity];
}
