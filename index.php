<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require __DIR__ . '/src/Response.php';
require __DIR__ . '/src/Slug.php';
require __DIR__ . '/src/Auth.php';
require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/Uploads.php';
require __DIR__ . '/src/Mailer.php';
require __DIR__ . '/handlers/public_events.php';
require __DIR__ . '/handlers/public_guests.php';
require __DIR__ . '/handlers/public_gifts.php';
require __DIR__ . '/handlers/admin_auth.php';
require __DIR__ . '/handlers/admin_password.php';
require __DIR__ . '/handlers/admin_event.php';
require __DIR__ . '/handlers/admin_guests.php';
require __DIR__ . '/handlers/admin_gifts.php';
require __DIR__ . '/handlers/super_events.php';
require __DIR__ . '/handlers/super_admins.php';
require __DIR__ . '/handlers/gift_templates.php';

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

// --- rotas públicas (evento e convidado) ---------------------------------
if ($method === 'GET' && preg_match('#^/events/([a-z0-9-]+)$#', $path, $m)) {
    handle_get_event($pdo, $m[1]);
}

if ($method === 'GET' && preg_match('#^/events/([a-z0-9-]+)/guests/([a-z0-9-]+)$#', $path, $m)) {
    handle_get_guest($pdo, $m[1], $m[2]);
}

if ($method === 'POST' && preg_match('#^/events/([a-z0-9-]+)/guests/([a-z0-9-]+)/confirm$#', $path, $m)) {
    handle_confirm_guest($pdo, $m[1], $m[2]);
}

if ($method === 'GET' && preg_match('#^/events/([a-z0-9-]+)/guests/([a-z0-9-]+)/gifts$#', $path, $m)) {
    handle_list_available_gifts($pdo, $m[1], $m[2]);
}

if ($method === 'POST' && preg_match('#^/events/([a-z0-9-]+)/guests/([a-z0-9-]+)/gifts/(\d+)/claim$#', $path, $m)) {
    handle_claim_gift($pdo, $m[1], $m[2], (int) $m[3]);
}

// --- autenticação e recuperação de senha admin ---------------------------
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
        'admin' => [
            'id' => (int) $admin['id'],
            'event_id' => $admin['event_id'],
            'name' => $admin['name'],
            'email' => $admin['email'],
        ],
    ]);
}

if ($method === 'POST' && $path === '/admin/forgot-password') {
    handle_forgot_password($pdo, $config);
}

if ($method === 'POST' && $path === '/admin/reset-password') {
    handle_reset_password($pdo);
}

// --- evento do admin autenticado ------------------------------------------
if ($method === 'GET' && $path === '/admin/event') {
    $admin = require_event_admin($pdo);
    handle_get_own_event($pdo, $admin['event_id']);
}

if ($method === 'POST' && $path === '/admin/event') {
    $admin = require_event_admin($pdo);
    handle_update_own_event($pdo, $admin['event_id']);
}

// --- convidados (pessoas e relacionados) ----------------------------------
if ($method === 'GET' && $path === '/admin/guests') {
    $admin = require_event_admin($pdo);
    handle_list_guests($pdo, $admin['event_id']);
}

if ($method === 'POST' && $path === '/admin/guests') {
    $admin = require_event_admin($pdo);
    handle_create_guest($pdo, $admin['event_id']);
}

if ($method === 'POST' && preg_match('#^/admin/guests/(\d+)/dependents$#', $path, $m)) {
    $admin = require_event_admin($pdo);
    handle_create_dependent($pdo, $admin['event_id'], (int) $m[1]);
}

if ($method === 'PUT' && preg_match('#^/admin/guests/(\d+)$#', $path, $m)) {
    $admin = require_event_admin($pdo);
    handle_update_guest($pdo, $admin['event_id'], (int) $m[1]);
}

if ($method === 'DELETE' && preg_match('#^/admin/guests/(\d+)$#', $path, $m)) {
    $admin = require_event_admin($pdo);
    handle_delete_guest($pdo, $admin['event_id'], (int) $m[1]);
}

// --- presentes --------------------------------------------------------------
if ($method === 'GET' && $path === '/admin/gifts') {
    $admin = require_event_admin($pdo);
    handle_list_gifts($pdo, $admin['event_id']);
}

if ($method === 'POST' && $path === '/admin/gifts') {
    $admin = require_event_admin($pdo);
    handle_create_gift($pdo, $admin['event_id']);
}

if ($method === 'POST' && preg_match('#^/admin/gifts/(\d+)$#', $path, $m)) {
    $admin = require_event_admin($pdo);
    handle_update_gift($pdo, $admin['event_id'], (int) $m[1]);
}

if ($method === 'DELETE' && preg_match('#^/admin/gifts/(\d+)$#', $path, $m)) {
    $admin = require_event_admin($pdo);
    handle_delete_gift($pdo, $admin['event_id'], (int) $m[1]);
}

// --- super-admin: eventos ----------------------------------------------------
if ($method === 'GET' && $path === '/admin/super/events') {
    require_super_admin($pdo);
    handle_list_events($pdo);
}

if ($method === 'POST' && $path === '/admin/super/events') {
    require_super_admin($pdo);
    handle_create_event($pdo, $config);
}

if ($method === 'GET' && preg_match('#^/admin/super/events/(\d+)$#', $path, $m)) {
    require_super_admin($pdo);
    handle_get_event_detail($pdo, (int) $m[1]);
}

if ($method === 'PUT' && preg_match('#^/admin/super/events/(\d+)$#', $path, $m)) {
    require_super_admin($pdo);
    handle_update_event($pdo, $config, (int) $m[1]);
}

if ($method === 'DELETE' && preg_match('#^/admin/super/events/(\d+)$#', $path, $m)) {
    require_super_admin($pdo);
    handle_delete_event($pdo, (int) $m[1]);
}

// --- super-admin: admins de um evento ----------------------------------------
if ($method === 'GET' && preg_match('#^/admin/super/events/(\d+)/admins$#', $path, $m)) {
    require_super_admin($pdo);
    handle_list_event_admins($pdo, (int) $m[1]);
}

if ($method === 'POST' && preg_match('#^/admin/super/events/(\d+)/admins$#', $path, $m)) {
    require_super_admin($pdo);
    handle_create_event_admin($pdo, $config, (int) $m[1]);
}

if ($method === 'PUT' && preg_match('#^/admin/super/events/(\d+)/admins/(\d+)$#', $path, $m)) {
    require_super_admin($pdo);
    handle_update_event_admin($pdo, (int) $m[1], (int) $m[2]);
}

if ($method === 'DELETE' && preg_match('#^/admin/super/events/(\d+)/admins/(\d+)$#', $path, $m)) {
    require_super_admin($pdo);
    handle_delete_event_admin($pdo, (int) $m[1], (int) $m[2]);
}

// --- super-admin: modelos de presente por tipo de evento --------------------
if ($method === 'GET' && $path === '/admin/super/gift-templates') {
    require_super_admin($pdo);
    handle_list_gift_templates($pdo);
}

if ($method === 'POST' && $path === '/admin/super/gift-templates') {
    require_super_admin($pdo);
    handle_create_gift_template($pdo);
}

if ($method === 'POST' && preg_match('#^/admin/super/gift-templates/(\d+)$#', $path, $m)) {
    require_super_admin($pdo);
    handle_update_gift_template($pdo, (int) $m[1]);
}

if ($method === 'DELETE' && preg_match('#^/admin/super/gift-templates/(\d+)$#', $path, $m)) {
    require_super_admin($pdo);
    handle_delete_gift_template($pdo, (int) $m[1]);
}

// --- admin de evento: prévia e clone dos modelos de presente -----------------
if ($method === 'GET' && $path === '/admin/gift-templates') {
    $admin = require_event_admin($pdo);
    handle_list_gift_templates_for_own_event($pdo, $admin['event_id']);
}

if ($method === 'POST' && $path === '/admin/gift-templates/clone') {
    $admin = require_event_admin($pdo);
    handle_clone_gift_templates($pdo, $admin['event_id']);
}

json_error(404, 'Rota não encontrada.');
