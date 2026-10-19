<?php

declare(strict_types=1);

const UPLOAD_MAX_BYTES = 10 * 1024 * 1024;
const UPLOAD_ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

const UPLOAD_AUDIO_MAX_BYTES = 15 * 1024 * 1024;
const UPLOAD_AUDIO_ALLOWED_MIME = [
    'audio/mpeg' => 'mp3',
    'audio/mp3' => 'mp3',
    'audio/mp4' => 'm4a',
    'audio/x-m4a' => 'm4a',
    // finfo costuma reportar .m4a como container MP4 genérico
    'video/mp4' => 'm4a',
    'audio/ogg' => 'ogg',
    'application/ogg' => 'ogg',
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

    return store_upload($file['tmp_name'], $subdir, UPLOAD_ALLOWED_MIME[$info['mime']], 'a imagem');
}

/**
 * Mesmo contrato de save_uploaded_image(), para a música do convite
 * (events.music_path). O tipo é detectado pelo conteúdo via finfo, não
 * pelo MIME enviado pelo browser.
 */
function save_uploaded_audio(array $file, string $subdir): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_error(422, 'Falha ao enviar a música.');
    }
    if ($file['size'] > UPLOAD_AUDIO_MAX_BYTES) {
        json_error(422, 'Música muito grande (máximo 15MB).');
    }

    // Sem a extensão fileinfo do PHP (vem desligada em alguns php.ini de
    // dev), trata como octet-stream e cai na checagem por extensão abaixo.
    $mime = class_exists('finfo')
        ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])
        : 'application/octet-stream';
    $ext = UPLOAD_AUDIO_ALLOWED_MIME[$mime] ?? null;

    // MP3 sem tag ID3 às vezes é detectado como octet-stream (e sem finfo
    // tudo vira octet-stream); nesse caso confia na extensão do nome original.
    if ($ext === null && $mime === 'application/octet-stream') {
        $originalExt = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $ext = in_array($originalExt, UPLOAD_AUDIO_ALLOWED_MIME, true) ? $originalExt : null;
    }
    if ($ext === null) {
        json_error(422, 'Formato de música inválido. Use MP3, M4A ou OGG.');
    }

    return store_upload($file['tmp_name'], $subdir, $ext, 'a música');
}

/**
 * Move um upload já validado para uploads/{$subdir}/ com nome aleatório e
 * retorna o caminho relativo ("uploads/..."). $label só entra na mensagem
 * de erro ("Não foi possível salvar a imagem.").
 */
function store_upload(string $tmpName, string $subdir, string $ext, string $label): string
{
    $dir = __DIR__ . '/../uploads/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        json_error(500, "Não foi possível salvar {$label}.");
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($tmpName, $dir . '/' . $filename)) {
        json_error(500, "Não foi possível salvar {$label}.");
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
