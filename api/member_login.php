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
check_rate_limit_json('member-login', 12, 900);

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$username = mb_strtolower(trim((string)($body['username'] ?? '')));
$password = (string)($body['password'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Benutzername und Passwort sind erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$members = load_member_users();
$found = null;
foreach ($members as $member) {
    if (!is_array($member)) continue;
    if (!empty($member['active']) && mb_strtolower((string)($member['username'] ?? '')) === $username) {
        $found = $member;
        break;
    }
}

if (!$found || empty($found['password_hash']) || !password_verify($password, (string)$found['password_hash'])) {
    register_rate_limit_failure('member-login');
    http_response_code(401);
    echo json_encode(['error' => 'Ungültige Zugangsdaten.'], JSON_UNESCAPED_UNICODE);
    exit;
}

start_secure_session();
$_SESSION['member_authenticated'] = true;
$_SESSION['member_username'] = (string)$found['username'];
$_SESSION['member_display_name'] = (string)($found['display_name'] ?? '');
$_SESSION['member_email'] = (string)($found['email'] ?? '');
$_SESSION['member_login_at'] = gmdate('c');
$_SESSION['last_activity_at'] = time();
clear_rate_limit_failures('member-login');
append_audit_log('member_login_success', [
    'username' => (string)$found['username'],
]);

echo json_encode([
    'ok' => true,
    'role' => 'member',
    'username' => (string)$found['username'],
    'display_name' => (string)($found['display_name'] ?? ''),
    'email' => (string)($found['email'] ?? ''),
], JSON_UNESCAPED_UNICODE);
