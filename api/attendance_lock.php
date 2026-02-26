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
if ($sessionId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'session_id ist erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = __DIR__ . '/storage/attendance_sessions.json';
if (!is_file($file)) {
    http_response_code(404);
    echo json_encode(['error' => 'Sitzung nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$rawData = file_get_contents($file);
$sessions = is_string($rawData) ? json_decode($rawData, true) : null;
if (!is_array($sessions)) $sessions = [];

$found = false;
foreach ($sessions as &$s) {
    if (!is_array($s)) continue;
    if ((string)($s['session_id'] ?? '') !== $sessionId) continue;
    $s['locked'] = true;
    $s['locked_at'] = gmdate('c');
    $s['updated_at'] = gmdate('c');
    $found = true;
    break;
}
unset($s);

if (!$found) {
    http_response_code(404);
    echo json_encode(['error' => 'Sitzung nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$json = json_encode(array_values($sessions), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if (!is_string($json) || file_put_contents($file, $json . PHP_EOL, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Sitzung konnte nicht gesperrt werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('attendance_lock', [
    'session_id' => $sessionId,
]);

echo json_encode(['ok' => true, 'session_id' => $sessionId], JSON_UNESCAPED_UNICODE);
