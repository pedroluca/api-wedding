<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require __DIR__ . '/src/Response.php';
require __DIR__ . '/src/Slug.php';
require __DIR__ . '/src/Auth.php';
require __DIR__ . '/src/Database.php';
require __DIR__ . '/handlers/public_guests.php';
require __DIR__ . '/handlers/admin_auth.php';
require __DIR__ . '/handlers/admin_users.php';
require __DIR__ . '/handlers/admin_guests.php';

$config = require __DIR__ . '/config.php';

// CORS: front e api vivem em subdomínios diferentes, então liberamos
// explicitamente as origens configuradas (sem uso de cookies).
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $config['cors']['allowed_origins'], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $pdo = db($config);
} catch (Throwable $e) {
    json_error(500, 'Falha ao conectar ao banco de dados.');
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = '/' . trim($path, '/');

if ($method === 'GET' && $path === '/health') {
    json_response(200, ['ok' => true]);
}

// --- rotas públicas (convidado) ---------------------------------------
if ($method === 'GET' && preg_match('#^/guests/([a-z0-9-]+)$#', $path, $m)) {
    handle_get_guest($pdo, $m[1]);
}

if ($method === 'POST' && preg_match('#^/guests/([a-z0-9-]+)/confirm$#', $path, $m)) {
    handle_confirm_guest($pdo, $m[1]);
}

// --- autenticação admin -------------------------------------------------
if ($method === 'POST' && $path === '/admin/login') {
    handle_admin_login($pdo, $config);
}

if ($method === 'POST' && $path === '/admin/logout') {
    $admin = require_admin($pdo);
    handle_admin_logout($pdo, $admin);
}

if ($method === 'GET' && $path === '/admin/me') {
    $admin = require_admin($pdo);
    json_response(200, [
        'admin' => ['id' => (int) $admin['id'], 'name' => $admin['name'], 'email' => $admin['email']],
    ]);
}

// --- usuários admin -------------------------------------------------------
if ($method === 'GET' && $path === '/admin/users') {
    require_admin($pdo);
    handle_list_admin_users($pdo);
}

if ($method === 'POST' && $path === '/admin/users') {
    require_admin($pdo);
    handle_create_admin_user($pdo);
}

if ($method === 'DELETE' && preg_match('#^/admin/users/(\d+)$#', $path, $m)) {
    $admin = require_admin($pdo);
    handle_delete_admin_user($pdo, (int) $m[1], $admin);
}

// --- convidados (pessoas e relacionados) ---------------------------------
if ($method === 'GET' && $path === '/admin/guests') {
    require_admin($pdo);
    handle_list_guests($pdo);
}

if ($method === 'POST' && $path === '/admin/guests') {
    require_admin($pdo);
    handle_create_guest($pdo);
}

if ($method === 'POST' && preg_match('#^/admin/guests/(\d+)/dependents$#', $path, $m)) {
    require_admin($pdo);
    handle_create_dependent($pdo, (int) $m[1]);
}

if ($method === 'PUT' && preg_match('#^/admin/guests/(\d+)$#', $path, $m)) {
    require_admin($pdo);
    handle_update_guest($pdo, (int) $m[1]);
}

if ($method === 'DELETE' && preg_match('#^/admin/guests/(\d+)$#', $path, $m)) {
    require_admin($pdo);
    handle_delete_guest($pdo, (int) $m[1]);
}

json_error(404, 'Rota não encontrada.');
