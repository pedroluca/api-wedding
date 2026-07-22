<?php

declare(strict_types=1);

/**
 * GET /guests/{slug}
 * Retorna o titular do link e todos os membros do grupo (ele + dependentes)
 * para montar a tela de convite/confirmação.
 */
function handle_get_guest(PDO $pdo, string $slug): void
{
    $stmt = $pdo->prepare('SELECT id, name FROM guests WHERE slug = :slug AND related_to_id IS NULL');
    $stmt->execute(['slug' => $slug]);
    $titular = $stmt->fetch();

    if (!$titular) {
        json_error(404, 'Convite não encontrado.');
    }

    $members = fetch_guest_group($pdo, (int) $titular['id']);

    json_response(200, [
        'titular' => $titular,
        'members' => $members,
        'has_dependents' => count($members) > 1,
    ]);
}

/**
 * POST /guests/{slug}/confirm
 * Body: { "items": [ { "id": 14, "confirmed": true }, ... ] }
 * Precisa incluir todos os membros do grupo (titular + dependentes).
 */
function handle_confirm_guest(PDO $pdo, string $slug): void
{
    $stmt = $pdo->prepare('SELECT id, name FROM guests WHERE slug = :slug AND related_to_id IS NULL');
    $stmt->execute(['slug' => $slug]);
    $titular = $stmt->fetch();

    if (!$titular) {
        json_error(404, 'Convite não encontrado.');
    }

    $groupIds = array_column(fetch_guest_group($pdo, (int) $titular['id']), 'id');

    $body = json_body();
    $items = $body['items'] ?? null;
    if (!is_array($items) || count($items) === 0) {
        json_error(422, 'Informe ao menos um item em "items".');
    }

    $updates = [];
    foreach ($items as $item) {
        if (!is_array($item) || !isset($item['id'], $item['confirmed'])) {
            json_error(422, 'Cada item precisa de "id" e "confirmed".');
        }

        $id = (int) $item['id'];
        if (!in_array($id, $groupIds, true)) {
            json_error(422, 'Um dos ids informados não pertence a este convite.');
        }

        $updates[$id] = (bool) $item['confirmed'];
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            'UPDATE guests SET status = :status, confirmed_at = NOW() WHERE id = :id'
        );
        foreach ($updates as $id => $confirmed) {
            $update->execute([
                'status' => $confirmed ? 'confirmado' : 'recusado',
                'id' => $id,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error(500, 'Não foi possível salvar a confirmação.');
    }

    json_response(200, [
        'titular' => $titular,
        'members' => fetch_guest_group($pdo, (int) $titular['id']),
    ]);
}

/**
 * Retorna o titular + dependentes (grupo completo) de um id de titular.
 */
function fetch_guest_group(PDO $pdo, int $titularId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, status, related_to_id
         FROM guests
         WHERE id = :id1 OR related_to_id = :id2
         ORDER BY (related_to_id IS NULL) DESC, id ASC'
    );
    $stmt->execute(['id1' => $titularId, 'id2' => $titularId]);

    return array_map(
        static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'status' => $row['status'],
            'is_titular' => $row['related_to_id'] === null,
        ],
        $stmt->fetchAll()
    );
}
