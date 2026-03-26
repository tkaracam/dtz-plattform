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
$member = require_member_session_json();

$body = require_json_body_or_400(65536);

$displayName = trim((string)($body['display_name'] ?? ''));
$email = trim((string)($body['email'] ?? ''));
$currentPassword = (string)($body['current_password'] ?? '');
$newPassword = (string)($body['new_password'] ?? '');

if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    http_response_code(400);
    echo json_encode(['error' => 'E-Mail-Adresse ist ungültig.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$members = load_member_users();
$updated = false;
foreach ($members as &$row) {
    if (!is_array($row)) {
        continue;
    }
    if (auth_lower_text((string)($row['username'] ?? '')) !== auth_lower_text((string)($member['username'] ?? ''))) {
        continue;
    }

    if ($newPassword !== '') {
        if ($currentPassword === '' || empty($row['password_hash']) || !password_verify($currentPassword, (string)$row['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Aktuelles Passwort ist falsch.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pwdCheck = validate_password_policy($newPassword, (string)($member['username'] ?? ''));
        if (empty($pwdCheck['ok'])) {
            http_response_code(400);
            echo json_encode(['error' => (string)($pwdCheck['error'] ?? 'Ungültiges Passwort.')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!empty($row['password_hash']) && password_verify($newPassword, (string)$row['password_hash'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Neues Passwort darf nicht dem aktuellen Passwort entsprechen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $row['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $updated = true;
    }

    if ($displayName !== '') {
        $row['display_name'] = $displayName;
        $updated = true;
    }
    if ($email !== '') {
        $row['email'] = $email;
        $updated = true;
    }
    if ($updated) {
        $row['updated_at'] = gmdate('c');
    }
    break;
}
unset($row);

if ($updated && !write_member_users($members)) {
    http_response_code(500);
    echo json_encode(['error' => 'Änderungen konnten nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($displayName !== '') {
    $_SESSION['member_display_name'] = $displayName;
}
if ($email !== '') {
    $_SESSION['member_email'] = $email;
}

append_audit_log('member_update', [
    'username' => (string)$member['username'],
    'updated_display_name' => $displayName !== '',
    'updated_email' => $email !== '',
    'updated_password' => $newPassword !== '',
]);

echo json_encode([
    'ok' => true,
    'username' => (string)$member['username'],
    'display_name' => $displayName !== '' ? $displayName : (string)($member['display_name'] ?? ''),
    'email' => $email !== '' ? $email : (string)($member['email'] ?? ''),
], JSON_UNESCAPED_UNICODE);
