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

$title = trim((string)($body['title'] ?? 'DTZ-Sitzung'));
$prompt = trim((string)($body['prompt'] ?? ''));
$duration = max(5, min(180, (int)($body['duration_minutes'] ?? 45)));
if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => 'prompt ist erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Storage konnte nicht erstellt werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $storageDir . '/virtual_rooms.json';
$rooms = [];
if (is_file($file)) {
    $rawRooms = file_get_contents($file);
    $tmp = is_string($rawRooms) ? json_decode($rawRooms, true) : null;
    if (is_array($tmp)) {
        $rooms = $tmp;
    }
}

try {
    $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
} catch (Throwable $e) {
    $id = gmdate('YmdHis') . '-' . substr(md5((string)mt_rand()), 0, 6);
}

$codeChars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$code = '';
for ($i = 0; $i < 6; $i++) {
    $code .= $codeChars[random_int(0, strlen($codeChars) - 1)];
}

$start = time();
$end = $start + ($duration * 60);

$room = [
    'room_id' => $id,
    'code' => $code,
    'title' => $title,
    'prompt' => $prompt,
    'duration_minutes' => $duration,
    'created_at' => gmdate('c'),
    'start_at' => gmdate('c', $start),
    'end_at' => gmdate('c', $end),
    'status' => 'active',
    'participants' => [],
];

$rooms[] = $room;
if (file_put_contents($file, json_encode(array_values($rooms), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Room-Datei konnte nicht geschrieben werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('room_create', ['room_id' => $id, 'code' => $code, 'duration' => $duration]);

echo json_encode(['ok' => true, 'room' => $room], JSON_UNESCAPED_UNICODE);
