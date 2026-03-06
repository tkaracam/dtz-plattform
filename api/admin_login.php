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

$ownerUsername = getenv('ADMIN_PANEL_USERNAME') ?: '';
if ($ownerUsername === '' && defined('ADMIN_PANEL_USERNAME') && ADMIN_PANEL_USERNAME !== '') {
    $ownerUsername = (string)ADMIN_PANEL_USERNAME;
}
$ownerUsername = mb_strtolower(trim($ownerUsername));
if ($ownerUsername === '') {
    $ownerUsername = 'admin';
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$username = mb_strtolower(trim((string)($body['username'] ?? '')));
$password = trim((string)($body['password'] ?? ''));
if ($password === '') {
    register_rate_limit_failure('admin-login');
    http_response_code(401);
    echo json_encode(['error' => 'Passwort ist erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($username === '') {
    $username = $ownerUsername;
}

$loginRole = '';
$loginDisplayName = '';

if (hash_equals($adminPassword, $password)) {
    $loginRole = 'owner';
    $username = $ownerUsername;
    $loginDisplayName = 'Haupt-Admin';
} else {
    $teachers = load_teacher_users();
    $foundTeacher = null;
    foreach ($teachers as $teacher) {
        if (!is_array($teacher)) {
            continue;
        }
        $teacherUsername = mb_strtolower(trim((string)($teacher['username'] ?? '')));
        if ($teacherUsername !== $username) {
            continue;
        }
        if (empty($teacher['active'])) {
            continue;
        }
        $hash = (string)($teacher['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            break;
        }
        $foundTeacher = $teacher;
        break;
    }
    if (is_array($foundTeacher)) {
        $loginRole = 'docent';
        $loginDisplayName = trim((string)($foundTeacher['display_name'] ?? ''));
    }
}

if ($loginRole === '') {
    register_rate_limit_failure('admin-login');
    http_response_code(401);
    echo json_encode(['error' => 'Ungueltige Zugangsdaten.'], JSON_UNESCAPED_UNICODE);
    exit;
}

start_secure_session();
$_SESSION['admin_authenticated'] = true;
$_SESSION['admin_role'] = $loginRole;
$_SESSION['admin_username'] = $username;
$_SESSION['admin_display_name'] = $loginDisplayName;
$_SESSION['admin_login_at'] = gmdate('c');
$_SESSION['last_activity_at'] = time();
clear_rate_limit_failures('admin-login');
append_audit_log('admin_login_success', [
    'username' => $username,
    'role' => $loginRole,
]);

echo json_encode([
    'ok' => true,
    'role' => $loginRole,
    'username' => $username,
    'display_name' => $loginDisplayName,
], JSON_UNESCAPED_UNICODE);
