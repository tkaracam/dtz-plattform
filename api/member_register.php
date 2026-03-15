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
check_rate_limit_json('member-register', 8, 900);

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$username = mb_strtolower(trim((string)($body['username'] ?? '')));
$password = (string)($body['password'] ?? '');
$displayName = trim((string)($body['display_name'] ?? ''));
$email = trim((string)($body['email'] ?? ''));

if (!preg_match('/^[a-z0-9._-]{6,32}$/', $username)) {
    http_response_code(400);
    echo json_encode(['error' => 'Benutzername muss 6-32 Zeichen haben (a-z, 0-9, ., _, -).'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'Passwort muss mindestens 6 Zeichen haben.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/[A-ZÄÖÜ]/u', $password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Passwort muss mindestens einen Großbuchstaben enthalten.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$members = load_member_users();
foreach ($members as $member) {
    if (!is_array($member)) continue;
    if (mb_strtolower((string)($member['username'] ?? '')) === $username) {
        http_response_code(409);
        echo json_encode(['error' => 'Benutzername existiert bereits.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

foreach (load_student_users() as $student) {
    if (!is_array($student)) continue;
    if (mb_strtolower((string)($student['username'] ?? '')) === $username) {
        http_response_code(409);
        echo json_encode(['error' => 'Benutzername existiert bereits.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$members[] = [
    'username' => $username,
    'display_name' => $displayName,
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'active' => true,
    'created_at' => gmdate('c'),
];

if (!write_member_users($members)) {
    http_response_code(500);
    echo json_encode(['error' => 'Mitglied konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('member_register', [
    'username' => $username,
]);

echo json_encode([
    'ok' => true,
    'username' => $username,
    'display_name' => $displayName,
    'email' => $email,
], JSON_UNESCAPED_UNICODE);
