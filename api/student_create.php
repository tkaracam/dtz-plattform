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
$admin = require_admin_role_json(['hauptadmin', 'docent']);

$body = require_json_body_or_400(65536);

$username = mb_strtolower(trim((string)($body['username'] ?? '')));
$password = (string)($body['password'] ?? '');
$displayName = trim((string)($body['display_name'] ?? ''));
$email = trim((string)($body['email'] ?? ''));
$phone = trim((string)($body['phone'] ?? ''));
$teacherUsername = mb_strtolower(trim((string)($body['teacher_username'] ?? '')));

if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
    http_response_code(400);
    echo json_encode(['error' => 'Benutzername muss 3-32 Zeichen haben (a-z, 0-9, ., _, -).'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($admin['role'] ?? '') === 'docent') {
    $teacherUsername = (string)($admin['username'] ?? '');
}

[$pwdOk, $pwdErr] = [false, ''];
$pwdCheck = validate_password_policy($password, $username);
$pwdOk = !empty($pwdCheck['ok']);
$pwdErr = (string)($pwdCheck['error'] ?? 'Ungültiges Passwort.');
if (!$pwdOk) {
    http_response_code(400);
    echo json_encode(['error' => $pwdErr], JSON_UNESCAPED_UNICODE);
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
    'teacher_username' => $teacherUsername,
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
    'teacher_username' => $teacherUsername,
]);

echo json_encode([
    'ok' => true,
    'username' => $username,
    'display_name' => $displayName,
    'email' => $email,
    'phone' => $phone,
    'teacher_username' => $teacherUsername,
], JSON_UNESCAPED_UNICODE);
