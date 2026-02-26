<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
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

$code = strtoupper(trim((string)($body['code'] ?? '')));
if ($code === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Raumcode fehlt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = __DIR__ . '/storage/virtual_rooms.json';
$rooms = [];
if (is_file($file)) {
    $rawRooms = file_get_contents($file);
    $tmp = is_string($rawRooms) ? json_decode($rawRooms, true) : null;
    if (is_array($tmp)) $rooms = $tmp;
}

$found = false;
foreach ($rooms as &$room) {
    if (strtoupper((string)($room['code'] ?? '')) !== $code) continue;
    $room['status'] = 'closed';
    $found = true;
    break;
}
unset($room);

if (!$found) {
    http_response_code(404);
    echo json_encode(['error' => 'Raum nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (file_put_contents($file, json_encode(array_values($rooms), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Raum konnte nicht aktualisiert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('room_close', ['code' => $code]);

echo json_encode(['ok' => true, 'code' => $code], JSON_UNESCAPED_UNICODE);
