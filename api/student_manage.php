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

$action = trim((string)($body['action'] ?? ''));
$username = mb_strtolower(trim((string)($body['username'] ?? '')));

if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiger Benutzername.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$users = load_student_users();
$foundIndex = -1;
foreach ($users as $i => $user) {
    if (mb_strtolower((string)($user['username'] ?? '')) === $username) {
        $foundIndex = $i;
        break;
    }
}

if ($foundIndex < 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Benutzer nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'reset_password') {
    $newPassword = (string)($body['new_password'] ?? '');
    if (mb_strlen($newPassword) < 6 || mb_strlen($newPassword) > 128) {
        http_response_code(400);
        echo json_encode(['error' => 'Passwort muss 6-128 Zeichen haben.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $users[$foundIndex]['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    $users[$foundIndex]['updated_at'] = gmdate('c');
} elseif ($action === 'set_active') {
    $active = !empty($body['active']);
    $users[$foundIndex]['active'] = $active;
    $users[$foundIndex]['updated_at'] = gmdate('c');
} elseif ($action === 'update_contact') {
    $email = trim((string)($body['email'] ?? ''));
    $phone = trim((string)($body['phone'] ?? ''));
    $users[$foundIndex]['email'] = $email;
    $users[$foundIndex]['phone'] = $phone;
    $users[$foundIndex]['updated_at'] = gmdate('c');
} elseif ($action === 'delete') {
    $deletedUser = $users[$foundIndex];
    array_splice($users, $foundIndex, 1);
    if (!write_student_users($users)) {
        http_response_code(500);
        echo json_encode(['error' => 'Aenderung konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    append_audit_log('student_manage', [
        'username' => $username,
        'action' => $action,
    ]);
    echo json_encode([
        'ok' => true,
        'username' => $username,
        'action' => $action,
        'active' => false,
        'email' => (string)($deletedUser['email'] ?? ''),
        'phone' => (string)($deletedUser['phone'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltige Aktion.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!write_student_users($users)) {
    http_response_code(500);
    echo json_encode(['error' => 'Aenderung konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('student_manage', [
    'username' => $username,
    'action' => $action,
]);

echo json_encode([
    'ok' => true,
    'username' => $username,
    'action' => $action,
    'active' => (bool)($users[$foundIndex]['active'] ?? false),
    'email' => (string)($users[$foundIndex]['email'] ?? ''),
    'phone' => (string)($users[$foundIndex]['phone'] ?? ''),
], JSON_UNESCAPED_UNICODE);
