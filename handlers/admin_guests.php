<?php

declare(strict_types=1);

/**
 * GET /admin/guests
 * Lista todos os titulares com seus dependentes aninhados. Serve tanto para
 * a tela de cadastro de pessoas quanto para a lista de presenças (o front
 * filtra por status a partir deste mesmo payload).
 */
function handle_list_guests(PDO $pdo): void
{
    $stmt = $pdo->query(
        'SELECT id, name, slug, related_to_id, status, confirmed_at, created_at
         FROM guests
         ORDER BY (related_to_id IS NULL) DESC, COALESCE(related_to_id, id) ASC, id ASC'
    );
    $rows = $stmt->fetchAll();

    $titulares = [];
    foreach ($rows as $row) {
        if ($row['related_to_id'] === null) {
            $titulares[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'status' => $row['status'],
                'confirmed_at' => $row['confirmed_at'],
                'created_at' => $row['created_at'],
                'dependents' => [],
            ];
        }
    }

    foreach ($rows as $row) {
        if ($row['related_to_id'] !== null && isset($titulares[(int) $row['related_to_id']])) {
            $titulares[(int) $row['related_to_id']]['dependents'][] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'status' => $row['status'],
                'confirmed_at' => $row['confirmed_at'],
                'created_at' => $row['created_at'],
            ];
        }
    }

    json_response(200, ['guests' => array_values($titulares)]);
}

/**
 * POST /admin/guests
 * Body: { "name": "..." } — cria um convidado titular (com link próprio).
 */
function handle_create_guest(PDO $pdo): void
{
    $body = json_body();
    $name = require_string($body, 'name', 150);

    $stmt = $pdo->prepare('INSERT INTO guests (name) VALUES (:name)');
    $stmt->execute(['name' => $name]);
    $id = (int) $pdo->lastInsertId();

    $slug = generate_guest_slug($name, $id);
    $update = $pdo->prepare('UPDATE guests SET slug = :slug WHERE id = :id');
    $update->execute(['slug' => $slug, 'id' => $id]);

    json_response(201, [
        'guest' => [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'status' => 'pendente',
            'dependents' => [],
        ],
    ]);
}

/**
 * POST /admin/guests/{id}/dependents
 * Body: { "name": "..." } — adiciona um dependente ao titular {id}.
 */
function handle_create_dependent(PDO $pdo, int $titularId): void
{
    $titular = find_titular($pdo, $titularId);
    if (!$titular) {
        json_error(404, 'Convidado titular não encontrado.');
    }

    $body = json_body();
    $name = require_string($body, 'name', 150);

    $stmt = $pdo->prepare('INSERT INTO guests (name, related_to_id) VALUES (:name, :related_to_id)');
    $stmt->execute(['name' => $name, 'related_to_id' => $titularId]);

    json_response(201, [
        'dependent' => [
            'id' => (int) $pdo->lastInsertId(),
            'name' => $name,
            'status' => 'pendente',
        ],
    ]);
}

/**
 * PUT /admin/guests/{id}
 * Body: { "name": "..." } — renomeia titular ou dependente. O slug do
 * titular não muda, para não invalidar um link já enviado.
 */
function handle_update_guest(PDO $pdo, int $id): void
{
    $body = json_body();
    $name = require_string($body, 'name', 150);

    $stmt = $pdo->prepare('UPDATE guests SET name = :name WHERE id = :id');
    $stmt->execute(['name' => $name, 'id' => $id]);

    if ($stmt->rowCount() === 0) {
        json_error(404, 'Convidado não encontrado.');
    }

    json_response(200, ['ok' => true]);
}

/**
 * DELETE /admin/guests/{id}
 * Remove um titular (e seus dependentes, via cascade) ou um dependente.
 */
function handle_delete_guest(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('DELETE FROM guests WHERE id = :id');
    $stmt->execute(['id' => $id]);

    if ($stmt->rowCount() === 0) {
        json_error(404, 'Convidado não encontrado.');
    }

    json_response(200, ['ok' => true]);
}

function find_titular(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, name FROM guests WHERE id = :id AND related_to_id IS NULL');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}
