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

$sessionId = trim((string)($body['session_id'] ?? ''));
$reason = trim((string)($body['reason'] ?? ''));
$changes = trim((string)($body['changes'] ?? ''));
if ($sessionId === '' || $reason === '') {
    http_response_code(400);
    echo json_encode(['error' => 'session_id und reason sind erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$storage = __DIR__ . '/storage';
if (!is_dir($storage) && !mkdir($storage, 0775, true) && !is_dir($storage)) {
    http_response_code(500);
    echo json_encode(['error' => 'Storage nicht verfügbar.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$file = $storage . '/attendance_requests.json';
$requests = [];
if (is_file($file)) {
    $rawReq = file_get_contents($file);
    $tmp = is_string($rawReq) ? json_decode($rawReq, true) : null;
    if (is_array($tmp)) $requests = $tmp;
}

try {
    $suffix = bin2hex(random_bytes(4));
} catch (Throwable $e) {
    $suffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
}
$id = 'req-' . gmdate('YmdHis') . '-' . $suffix;

$requests[] = [
    'request_id' => $id,
    'session_id' => $sessionId,
    'reason' => $reason,
    'changes' => $changes,
    'status' => 'pending',
    'created_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
    'review_note' => '',
];

$json = json_encode(array_values($requests), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if (!is_string($json) || file_put_contents($file, $json . PHP_EOL, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Talep kaydedilemedi.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('attendance_request_create', [
    'request_id' => $id,
    'session_id' => $sessionId,
]);

echo json_encode(['ok' => true, 'request_id' => $id], JSON_UNESCAPED_UNICODE);
