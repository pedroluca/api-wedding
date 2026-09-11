<?php

declare(strict_types=1);

function json_response(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(int $status, string $message, ?string $code = null): void
{
    $data = ['error' => $message];
    if ($code !== null) {
        $data['code'] = $code;
    }
    json_response($status, $data);
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_string(array $body, string $key, int $maxLength = 190): string
{
    $value = trim((string) ($body[$key] ?? ''));
    if ($value === '') {
        json_error(422, "Campo obrigatório: {$key}.");
    }
    if (mb_strlen($value) > $maxLength) {
        json_error(422, "Campo muito longo: {$key}.");
    }

    return $value;
}

/**
 * Monta a URL pública absoluta de um arquivo salvo em uploads/ (ex:
 * imagem de presente), a partir do host da própria requisição atual.
 */
function public_asset_url(?string $path): ?string
{
    if ($path === null) {
        return null;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return "{$scheme}://{$host}/{$path}";
}
