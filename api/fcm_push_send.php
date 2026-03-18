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
require_once __DIR__ . '/fcm_lib.php';

$admin = require_admin_role_json(['hauptadmin', 'docent']);

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$title = trim((string)($body['title'] ?? 'DTZ-LID edu'));
$message = trim((string)($body['message'] ?? ''));
$targetAll = !empty($body['target_all']);
$usernames = is_array($body['usernames'] ?? null) ? $body['usernames'] : [];
$data = is_array($body['data'] ?? null) ? $body['data'] : [];

if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'message ist erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = load_fcm_tokens_lib();
$wanted = [];
if ($targetAll) {
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['active'])) {
            continue;
        }
        $uname = mb_strtolower(trim((string)($row['username'] ?? '')));
        if ($uname === '') {
            continue;
        }
        if (($admin['role_key'] ?? '') === 'docent' && !admin_can_access_student_username($uname, $admin)) {
            continue;
        }
        $wanted[] = (string)($row['token'] ?? '');
    }
} else {
    $allow = [];
    foreach ($usernames as $u) {
        $uname = mb_strtolower(trim((string)$u));
        if ($uname === '') {
            continue;
        }
        if (($admin['role_key'] ?? '') === 'docent' && !admin_can_access_student_username($uname, $admin)) {
            continue;
        }
        $allow[$uname] = true;
    }
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['active'])) {
            continue;
        }
        $uname = mb_strtolower(trim((string)($row['username'] ?? '')));
        if ($uname === '' || empty($allow[$uname])) {
            continue;
        }
        $wanted[] = (string)($row['token'] ?? '');
    }
}

$result = send_fcm_notification_legacy($wanted, $title, $message, $data);
if (empty($result['ok'])) {
    http_response_code(502);
    echo json_encode(['error' => (string)($result['error'] ?? 'FCM gönderimi başarısız.')], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('fcm_push_send', [
    'by' => (string)($admin['username'] ?? ''),
    'target_all' => $targetAll,
    'target_token_count' => count($wanted),
    'success' => (int)($result['success'] ?? 0),
    'failure' => (int)($result['failure'] ?? 0),
]);

echo json_encode([
    'ok' => true,
    'target_token_count' => count($wanted),
    'success' => (int)($result['success'] ?? 0),
    'failure' => (int)($result['failure'] ?? 0),
], JSON_UNESCAPED_UNICODE);

