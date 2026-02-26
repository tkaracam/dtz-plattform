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
require_admin_session_json();

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$username = mb_strtolower(trim((string)($body['username'] ?? '')));
$password = (string)($body['password'] ?? '');
$displayName = trim((string)($body['display_name'] ?? ''));
$email = trim((string)($body['email'] ?? ''));
$phone = trim((string)($body['phone'] ?? ''));

if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
    http_response_code(400);
    echo json_encode(['error' => 'Benutzername muss 3-32 Zeichen haben (a-z, 0-9, ., _, -).'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($password) < 6 || mb_strlen($password) > 128) {
    http_response_code(400);
    echo json_encode(['error' => 'Passwort muss 6-128 Zeichen haben.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$users = load_student_users();
foreach ($users as $user) {
    if (mb_strtolower((string)($user['username'] ?? '')) === $username) {
        http_response_code(409);
        echo json_encode(['error' => 'Benutzername existiert bereits.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$users[] = [
    'username' => $username,
    'display_name' => $displayName,
    'email' => $email,
    'phone' => $phone,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'active' => true,
    'created_at' => gmdate('c'),
];

if (!write_student_users($users)) {
    http_response_code(500);
    echo json_encode(['error' => 'Benutzer konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('student_create', [
    'username' => $username,
    'display_name' => $displayName,
]);

echo json_encode([
    'ok' => true,
    'username' => $username,
    'display_name' => $displayName,
    'email' => $email,
    'phone' => $phone,
], JSON_UNESCAPED_UNICODE);
