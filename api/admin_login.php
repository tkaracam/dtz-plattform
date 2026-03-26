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
    echo json_encode(['error' => 'Nur POST wird unterstützt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/auth.php';
$loginBucket = 'admin-login';
$loginMaxAttempts = 8;
$loginWindowSeconds = 10 * 60;
$loginLockSeconds = 15 * 60;
check_login_guard_json($loginBucket, '', $loginMaxAttempts, $loginWindowSeconds, $loginLockSeconds);
check_rate_limit_json($loginBucket, 30, 180);

$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

$allowedPasswords = [];
$envAdminPassword = getenv('ADMIN_PANEL_PASSWORD');
if (is_string($envAdminPassword) && $envAdminPassword !== '') {
    $allowedPasswords[] = $envAdminPassword;
}
if (defined('ADMIN_PANEL_PASSWORD') && ADMIN_PANEL_PASSWORD !== '') {
    $allowedPasswords[] = (string)ADMIN_PANEL_PASSWORD;
}
if (defined('ADMIN_PANEL_BACKUP_PASSWORD') && ADMIN_PANEL_BACKUP_PASSWORD !== '') {
    $allowedPasswords[] = (string)ADMIN_PANEL_BACKUP_PASSWORD;
}
if (defined('ADMIN_PANEL_KEY') && ADMIN_PANEL_KEY !== '') {
    $allowedPasswords[] = (string)ADMIN_PANEL_KEY;
}
$allowedPasswords = array_values(array_unique(array_filter(array_map(
    static fn($v) => trim((string)$v),
    $allowedPasswords
), static fn($v) => $v !== '')));

if (!$allowedPasswords) {
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

$body = require_json_body_or_400(32768);

$username = mb_strtolower(trim((string)($body['username'] ?? '')));
$password = trim((string)($body['password'] ?? ''));
check_login_guard_json($loginBucket, $username, $loginMaxAttempts, $loginWindowSeconds, $loginLockSeconds);
if ($password === '') {
    register_login_guard_failure($loginBucket, $username, $loginMaxAttempts, $loginWindowSeconds, $loginLockSeconds);
    register_rate_limit_failure('admin-login');
    http_response_code(401);
    echo json_encode(['error' => 'Passwort ist erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($username === '') {
    $username = $ownerUsername;
}

$requestedMode = auth_lower_text((string)($_SERVER['HTTP_X_ADMIN_MODE'] ?? ''));
if ($requestedMode === '') {
    $requestedMode = auth_lower_text((string)($_GET['mode'] ?? ''));
}

$loginRole = '';
$loginRoleKey = '';
$loginDisplayName = '';

$isOwnerAttempt = ($username === $ownerUsername) || $requestedMode === 'owner' || $requestedMode === 'hauptadmin';
if ($isOwnerAttempt) {
    $passwordOk = false;
    foreach ($allowedPasswords as $candidate) {
        if (hash_equals((string)$candidate, $password)) {
            $passwordOk = true;
            break;
        }
    }
    if ($passwordOk) {
        $loginRole = 'owner';
        $loginRoleKey = 'hauptadmin';
        $loginDisplayName = 'Haupt-Admin';
        $username = $ownerUsername;
    }
}

if ($loginRole === '') {
    $teachers = load_teacher_users();
    foreach ($teachers as $teacher) {
        if (!is_array($teacher)) {
            continue;
        }
        $teacherUsername = mb_strtolower(trim((string)($teacher['username'] ?? '')));
        if ($teacherUsername === '' || $teacherUsername !== $username) {
            continue;
        }
        if (empty($teacher['active'])) {
            register_rate_limit_failure('admin-login');
            http_response_code(403);
            echo json_encode(['error' => 'Dozent-Konto ist deaktiviert.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $hash = (string)($teacher['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            break;
        }
        $loginRole = 'docent';
        $loginRoleKey = 'docent';
        $display = trim((string)($teacher['display_name'] ?? ''));
        $loginDisplayName = $display !== '' ? $display : $teacherUsername;
        $username = $teacherUsername;
        break;
    }
}

if ($loginRole === '') {
    register_login_guard_failure($loginBucket, $username, $loginMaxAttempts, $loginWindowSeconds, $loginLockSeconds);
    register_rate_limit_failure('admin-login');
    http_response_code(401);
    echo json_encode(['error' => 'Ungültige Zugangsdaten.'], JSON_UNESCAPED_UNICODE);
    exit;
}

start_secure_session();
$_SESSION['admin_authenticated'] = true;
$_SESSION['admin_role'] = $loginRole;
$_SESSION['admin_role_key'] = $loginRoleKey !== '' ? $loginRoleKey : normalize_admin_role_key($loginRole);
$_SESSION['admin_username'] = $username;
$_SESSION['admin_display_name'] = $loginDisplayName;
$_SESSION['admin_login_at'] = gmdate('c');
$_SESSION['last_activity_at'] = time();
clear_rate_limit_failures('admin-login');
clear_login_guard_failures($loginBucket, $username);
append_audit_log('admin_login_success', [
    'username' => $username,
    'role' => $loginRole,
    'role_key' => $_SESSION['admin_role_key'],
]);

echo json_encode([
    'ok' => true,
    'role' => $loginRole,
    'role_key' => (string)$_SESSION['admin_role_key'],
    'username' => $username,
    'display_name' => $loginDisplayName,
], JSON_UNESCAPED_UNICODE);
