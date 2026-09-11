<?php

declare(strict_types=1);

const UPLOAD_MAX_BYTES = 10 * 1024 * 1024;
const UPLOAD_ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

function has_uploaded_file(string $field): bool
{
    return isset($_FILES[$field]) && $_FILES[$field]['error'] !== UPLOAD_ERR_NO_FILE;
}

/**
 * Valida e move o upload em $file para uploads/{$subdir}/, com nome aleatório.
 * Retorna o caminho relativo salvo (ex: gifts.image_path, events.logo_path),
 * já incluindo o prefixo "uploads/" — mesmo formato esperado por
 * public_asset_url() e delete_uploaded_file().
 */
function save_uploaded_image(array $file, string $subdir): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_error(422, 'Falha ao enviar a imagem.');
    }
    if ($file['size'] > UPLOAD_MAX_BYTES) {
        json_error(422, 'Imagem muito grande (máximo 10MB).');
    }

    $info = getimagesize($file['tmp_name']);
    if ($info === false || !isset(UPLOAD_ALLOWED_MIME[$info['mime']])) {
        json_error(422, 'Formato de imagem inválido. Use JPG, PNG ou WEBP.');
    }

    $dir = __DIR__ . '/../uploads/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        json_error(500, 'Não foi possível salvar a imagem.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . UPLOAD_ALLOWED_MIME[$info['mime']];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        json_error(500, 'Não foi possível salvar a imagem.');
    }

    return 'uploads/' . $subdir . '/' . $filename;
}

function delete_uploaded_file(?string $path): void
{
    if ($path === null) {
        return;
    }

    $full = __DIR__ . '/../' . $path;
    if (is_file($full)) {
        @unlink($full);
    }
}

/**
 * Copia o arquivo de $sourcePath (ex: um gift_templates.image_path) para
 * uploads/{$subdir}/ com um nome novo e aleatório, retornando o novo
 * caminho relativo — nunca o mesmo caminho de origem. É uma cópia física
 * de propósito: se duas linhas (ex: dois eventos que clonaram o mesmo
 * modelo) apontassem pro mesmo arquivo, editar/apagar uma delas apagaria
 * a imagem da outra por baixo dos panos (delete_uploaded_file() não sabe
 * que o arquivo é compartilhado).
 */
function copy_uploaded_image(?string $sourcePath, string $subdir): ?string
{
    if ($sourcePath === null) {
        return null;
    }

    $source = __DIR__ . '/../' . $sourcePath;
    if (!is_file($source)) {
        return null;
    }

    $dir = __DIR__ . '/../uploads/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }

    $ext = pathinfo($source, PATHINFO_EXTENSION);
    $filename = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
    if (!copy($source, $dir . '/' . $filename)) {
        return null;
    }

    return 'uploads/' . $subdir . '/' . $filename;
}
