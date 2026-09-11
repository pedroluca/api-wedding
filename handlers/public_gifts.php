<?php

declare(strict_types=1);

/**
 * GET /events/{eventSlug}/guests/{guestSlug}/gifts
 * Lista os presentes do evento com quantidade ainda disponível (itens
 * totalmente presenteados somem da lista). Marca claimed_by_me para o item
 * que o titular deste link já presenteou, mas ele continua podendo
 * escolher outros itens.
 */
function handle_list_available_gifts(PDO $pdo, string $eventSlug, string $guestSlug): void
{
    $event = find_event_by_slug($pdo, $eventSlug);
    if (!$event) {
        json_error(404, 'Convite não encontrado.');
    }

    $titular = find_titular_by_slug($pdo, $event['id'], $guestSlug);
    if (!$titular) {
        json_error(404, 'Convite não encontrado.');
    }

    $gifts = $pdo->prepare(
        'SELECT id, name, description, image_path, suggested_amount, quantity
         FROM gifts
         WHERE event_id = :event_id
         ORDER BY created_at ASC'
    );
    $gifts->execute(['event_id' => $event['id']]);

    $claimedCountStmt = $pdo->prepare('SELECT COUNT(*) FROM gift_claims WHERE gift_id = :gift_id');
    $claimedByMeStmt = $pdo->prepare(
        'SELECT id FROM gift_claims WHERE gift_id = :gift_id AND guest_id = :guest_id'
    );

    $result = [];
    foreach ($gifts->fetchAll() as $gift) {
        $claimedCountStmt->execute(['gift_id' => $gift['id']]);
        $remaining = (int) $gift['quantity'] - (int) $claimedCountStmt->fetchColumn();

        if ($remaining <= 0) {
            continue;
        }

        $claimedByMeStmt->execute(['gift_id' => $gift['id'], 'guest_id' => $titular['id']]);

        $result[] = [
            'id' => (int) $gift['id'],
            'name' => $gift['name'],
            'description' => $gift['description'],
            'image_url' => public_asset_url($gift['image_path']),
            'suggested_amount' => (float) $gift['suggested_amount'],
            'remaining' => $remaining,
            'claimed_by_me' => (bool) $claimedByMeStmt->fetch(),
        ];
    }

    json_response(200, ['gifts' => $result]);
}

/**
 * POST /events/{eventSlug}/guests/{guestSlug}/gifts/{id}/claim
 * Body: { "message": "..." } (opcional)
 * Marca o presente como escolhido pelo titular do convite identificado
 * pelo slug da URL. Usa lock de linha para não deixar duas pessoas
 * passarem da quantidade disponível ao confirmar ao mesmo tempo.
 *
 * O SELECT do presente é escopado por event_id (não só pelo id) para que
 * um gift_id de outro evento nunca possa ser reivindicado através de um
 * convidado válido de um evento diferente.
 */
function handle_claim_gift(PDO $pdo, string $eventSlug, string $guestSlug, int $giftId): void
{
    $event = find_event_by_slug($pdo, $eventSlug);
    if (!$event) {
        json_error(404, 'Convite não encontrado.');
    }

    $titular = find_titular_by_slug($pdo, $event['id'], $guestSlug);
    if (!$titular) {
        json_error(404, 'Convite não encontrado.');
    }

    $body = json_body();
    $message = trim((string) ($body['message'] ?? ''));
    if ($message !== '' && mb_strlen($message) > 500) {
        json_error(422, 'Mensagem muito longa.');
    }

    $pdo->beginTransaction();
    try {
        $gift = $pdo->prepare('SELECT id, quantity FROM gifts WHERE id = :id AND event_id = :event_id FOR UPDATE');
        $gift->execute(['id' => $giftId, 'event_id' => $event['id']]);
        $giftRow = $gift->fetch();
        if (!$giftRow) {
            $pdo->rollBack();
            json_error(404, 'Presente não encontrado.');
        }

        $claimedStmt = $pdo->prepare('SELECT COUNT(*) FROM gift_claims WHERE gift_id = :gift_id');
        $claimedStmt->execute(['gift_id' => $giftId]);
        if ((int) $claimedStmt->fetchColumn() >= (int) $giftRow['quantity']) {
            $pdo->rollBack();
            json_error(409, 'Este item já foi totalmente presenteado.');
        }

        $mineStmt = $pdo->prepare(
            'SELECT id FROM gift_claims WHERE gift_id = :gift_id AND guest_id = :guest_id'
        );
        $mineStmt->execute(['gift_id' => $giftId, 'guest_id' => $titular['id']]);
        if ($mineStmt->fetch()) {
            $pdo->rollBack();
            json_error(409, 'Você já presenteou este item.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO gift_claims (gift_id, guest_id, message) VALUES (:gift_id, :guest_id, :message)'
        );
        $insert->execute([
            'gift_id' => $giftId,
            'guest_id' => $titular['id'],
            'message' => $message === '' ? null : $message,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_error(500, 'Não foi possível registrar o presente.');
    }

    json_response(201, ['ok' => true]);
}
