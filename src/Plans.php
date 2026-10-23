<?php

declare(strict_types=1);

/**
 * Planos do evento. 'unlimited' (Ilimitado) é interno: nunca aparece em venda
 * nem para o cliente, e o super-admin pode colocar qualquer evento nele.
 */
const EVENT_PLANS = ['standard', 'premium', 'unlimited'];

/**
 * O que o evento pode, juntando o plano com o que foi contratado. Tudo que o
 * sistema libera ou bloqueia por plano passa por aqui: uma função Premium
 * nova é uma linha a mais neste array e um if onde ela aparece.
 *
 * @param array $event linha de events com plan, gifts_enabled, guest_limit e admin_limit
 * @return array{unlimited:bool,premium:bool,gifts:bool,access_expires:bool,guest_limit:?int,admin_limit:?int}
 */
function event_entitlements(array $event): array
{
    $unlimited = $event['plan'] === 'unlimited';
    $limit = static fn ($value): ?int => $unlimited || $value === null ? null : (int) $value;

    return [
        'unlimited' => $unlimited,
        // funções exclusivas do Premium (que o Ilimitado também tem)
        'premium' => $event['plan'] !== 'standard',
        'gifts' => $unlimited || (bool) $event['gifts_enabled'],
        'access_expires' => !$unlimited,
        'guest_limit' => $limit($event['guest_limit']),
        'admin_limit' => $limit($event['admin_limit']),
    ];
}

/**
 * Dias de acesso ao painel depois da data do evento. O Premium tem o dobro
 * do Padrão; o Ilimitado não tem prazo (null).
 */
function plan_grace_days(string $plan, array $config): ?int
{
    return match ($plan) {
        'unlimited' => null,
        'premium' => (int) ($config['access']['grace_days_premium'] ?? 30),
        default => (int) ($config['access']['grace_days'] ?? 15),
    };
}

function read_plan(array $body): string
{
    $plan = trim((string) ($body['plan'] ?? ''));
    if (!in_array($plan, EVENT_PLANS, true)) {
        json_error(422, 'Plano inválido.');
    }

    return $plan;
}

/**
 * Plano e contratado, gravados só pelo super-admin. Como os campos de marca
 * mais novos (read_sent_branding_fields), só entram no INSERT/UPDATE quando
 * vêm no corpo: um front ainda sem esses campos salva o evento sem apagá-los.
 *
 * @return array<string, mixed> coluna => valor, só com os campos enviados
 */
function read_sent_plan_fields(array $body): array
{
    $fields = [];
    if (array_key_exists('plan', $body)) {
        $fields['plan'] = read_plan($body);
    }
    if (array_key_exists('gifts_enabled', $body)) {
        $fields['gifts_enabled'] = read_flag($body, 'gifts_enabled');
    }
    foreach (['guest_limit', 'admin_limit'] as $key) {
        if (array_key_exists($key, $body)) {
            $fields[$key] = optional_limit($body, $key);
        }
    }

    return $fields;
}

/** Limite contratado (inteiro a partir de 1). Em branco = sem limite. */
function optional_limit(array $body, string $key): ?int
{
    $raw = trim((string) ($body[$key] ?? ''));
    if ($raw === '') {
        return null;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) {
        json_error(422, "Valor inválido: {$key}.");
    }

    return $value;
}

/**
 * Para as rotas de presentes do painel do evento: com o módulo desligado
 * no plano, respondem 403 feature_disabled. As rotas públicas respondem 404
 * (handlers/public_gifts.php), como se a lista não existisse.
 */
function require_gifts_module(PDO $pdo, int $eventId): void
{
    $event = fetch_event_row($pdo, $eventId);
    if ($event && !event_entitlements($event)['gifts']) {
        json_error(403, 'A lista de presentes não está ativa neste evento.', 'feature_disabled');
    }
}
