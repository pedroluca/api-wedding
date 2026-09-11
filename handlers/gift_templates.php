<?php

declare(strict_types=1);

/**
 * GET /admin/super/gift-templates
 * Lista todos os modelos de presente (dos dois tipos de evento), para a
 * tela de gestão do admin superior.
 */
function handle_list_gift_templates(PDO $pdo): void
{
    $stmt = $pdo->query(
        'SELECT id, event_type, name, description, image_path, suggested_amount, quantity, created_at
         FROM gift_templates
         ORDER BY event_type ASC, created_at ASC'
    );

    json_response(200, ['gift_templates' => array_map('format_gift_template', $stmt->fetchAll())]);
}

/**
 * POST /admin/super/gift-templates
 * multipart/form-data: event_type, name, description?, suggested_amount, quantity, image?
 */
function handle_create_gift_template(PDO $pdo): void
{
    $eventType = read_event_type($_POST);
    [$name, $description, $amount, $quantity] = read_gift_fields($_POST);
    $imagePath = has_uploaded_file('image') ? save_uploaded_image($_FILES['image'], 'gift_templates') : null;

    $stmt = $pdo->prepare(
        'INSERT INTO gift_templates (event_type, name, description, image_path, suggested_amount, quantity)
         VALUES (:event_type, :name, :description, :image_path, :suggested_amount, :quantity)'
    );
    $stmt->execute([
        'event_type' => $eventType,
        'name' => $name,
        'description' => $description,
        'image_path' => $imagePath,
        'suggested_amount' => $amount,
        'quantity' => $quantity,
    ]);

    json_response(201, [
        'gift_template' => [
            'id' => (int) $pdo->lastInsertId(),
            'event_type' => $eventType,
            'name' => $name,
            'description' => $description,
            'image_url' => public_asset_url($imagePath),
            'suggested_amount' => $amount,
            'quantity' => $quantity,
        ],
    ]);
}

/**
 * POST /admin/super/gift-templates/{id}
 * Atualização via POST (mesma razão de admin_gifts.php: $_FILES só é
 * populado em multipart via POST).
 */
function handle_update_gift_template(PDO $pdo, int $id): void
{
    $current = $pdo->prepare('SELECT image_path FROM gift_templates WHERE id = :id');
    $current->execute(['id' => $id]);
    $existing = $current->fetch();
    if (!$existing) {
        json_error(404, 'Modelo de presente não encontrado.');
    }

    $eventType = read_event_type($_POST);
    [$name, $description, $amount, $quantity] = read_gift_fields($_POST);

    $imagePath = $existing['image_path'];
    if (has_uploaded_file('image')) {
        $imagePath = save_uploaded_image($_FILES['image'], 'gift_templates');
        delete_uploaded_file($existing['image_path']);
    }

    $stmt = $pdo->prepare(
        'UPDATE gift_templates
         SET event_type = :event_type, name = :name, description = :description,
             image_path = :image_path, suggested_amount = :suggested_amount, quantity = :quantity
         WHERE id = :id'
    );
    $stmt->execute([
        'event_type' => $eventType,
        'name' => $name,
        'description' => $description,
        'image_path' => $imagePath,
        'suggested_amount' => $amount,
        'quantity' => $quantity,
        'id' => $id,
    ]);

    json_response(200, ['ok' => true]);
}

/**
 * DELETE /admin/super/gift-templates/{id}
 */
function handle_delete_gift_template(PDO $pdo, int $id): void
{
    $current = $pdo->prepare('SELECT image_path FROM gift_templates WHERE id = :id');
    $current->execute(['id' => $id]);
    $existing = $current->fetch();
    if (!$existing) {
        json_error(404, 'Modelo de presente não encontrado.');
    }

    $pdo->prepare('DELETE FROM gift_templates WHERE id = :id')->execute(['id' => $id]);
    delete_uploaded_file($existing['image_path']);

    json_response(200, ['ok' => true]);
}

/**
 * GET /admin/gift-templates
 * Modelos que batem com o event_type do próprio evento do admin
 * autenticado — usado como prévia antes de decidir clonar.
 */
function handle_list_gift_templates_for_own_event(PDO $pdo, int $eventId): void
{
    $eventType = find_event_type($pdo, $eventId);

    $stmt = $pdo->prepare(
        'SELECT id, event_type, name, description, image_path, suggested_amount, quantity, created_at
         FROM gift_templates
         WHERE event_type = :event_type
         ORDER BY created_at ASC'
    );
    $stmt->execute(['event_type' => $eventType]);

    json_response(200, ['gift_templates' => array_map('format_gift_template', $stmt->fetchAll())]);
}

/**
 * POST /admin/gifts/clone-templates
 * Clona todos os modelos do event_type do evento do admin autenticado para
 * dentro de `gifts` (imagem incluída, copiada fisicamente — ver
 * copy_uploaded_image() em src/Uploads.php). Recusa se o evento já tiver
 * algum presente cadastrado, para não duplicar por engano (ex: duplo
 * clique) nem depois de o admin já ter começado a montar a lista dele.
 */
function handle_clone_gift_templates(PDO $pdo, int $eventId): void
{
    $existingCountStmt = $pdo->prepare('SELECT COUNT(*) FROM gifts WHERE event_id = :event_id');
    $existingCountStmt->execute(['event_id' => $eventId]);
    if ((int) $existingCountStmt->fetchColumn() > 0) {
        json_error(409, 'Este evento já tem presentes cadastrados.');
    }

    $eventType = find_event_type($pdo, $eventId);

    $templatesStmt = $pdo->prepare(
        'SELECT name, description, image_path, suggested_amount, quantity
         FROM gift_templates
         WHERE event_type = :event_type
         ORDER BY created_at ASC'
    );
    $templatesStmt->execute(['event_type' => $eventType]);
    $templates = $templatesStmt->fetchAll();

    $insert = $pdo->prepare(
        'INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
         VALUES (:event_id, :name, :description, :image_path, :suggested_amount, :quantity)'
    );

    $pdo->beginTransaction();
    try {
        foreach ($templates as $template) {
            $insert->execute([
                'event_id' => $eventId,
                'name' => $template['name'],
                'description' => $template['description'],
                'image_path' => copy_uploaded_image($template['image_path'], 'gifts'),
                'suggested_amount' => $template['suggested_amount'],
                'quantity' => $template['quantity'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error(500, 'Não foi possível clonar a lista de sugestões.');
    }

    json_response(201, ['cloned' => count($templates)]);
}

function find_event_type(PDO $pdo, int $eventId): string
{
    $stmt = $pdo->prepare('SELECT event_type FROM events WHERE id = :id');
    $stmt->execute(['id' => $eventId]);
    $event = $stmt->fetch();
    if (!$event) {
        json_error(404, 'Evento não encontrado.');
    }

    return $event['event_type'];
}

function format_gift_template(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'event_type' => $row['event_type'],
        'name' => $row['name'],
        'description' => $row['description'],
        'image_url' => public_asset_url($row['image_path']),
        'suggested_amount' => (float) $row['suggested_amount'],
        'quantity' => (int) $row['quantity'],
        'created_at' => $row['created_at'],
    ];
}
