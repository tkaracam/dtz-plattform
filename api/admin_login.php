<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST wird unterstuetzt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/auth.php';
check_rate_limit_json('admin-login', 8, 900);

$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

$adminPassword = getenv('ADMIN_PANEL_PASSWORD') ?: '';
if (defined('ADMIN_PANEL_PASSWORD') && ADMIN_PANEL_PASSWORD !== '') {
    $adminPassword = ADMIN_PANEL_PASSWORD;
}
if ($adminPassword === '' && defined('ADMIN_PANEL_KEY') && ADMIN_PANEL_KEY !== '') {
    $adminPassword = ADMIN_PANEL_KEY;
}
if ($adminPassword === '') {
    http_response_code(500);
    echo json_encode(['error' => 'ADMIN_PANEL_PASSWORD ist nicht gesetzt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$password = trim((string)($body['password'] ?? ''));
if ($password === '' || !hash_equals($adminPassword, $password)) {
    register_rate_limit_failure('admin-login');
    http_response_code(401);
    echo json_encode(['error' => 'Falsches Passwort.'], JSON_UNESCAPED_UNICODE);
    exit;
}

start_secure_session();
$_SESSION['admin_authenticated'] = true;
$_SESSION['admin_login_at'] = gmdate('c');
$_SESSION['last_activity_at'] = time();
clear_rate_limit_failures('admin-login');
append_audit_log('admin_login_success', []);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
