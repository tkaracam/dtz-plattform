<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur GET wird unterstuetzt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/auth.php';
$student = require_student_session_json();
$code = strtoupper(trim((string)($_GET['code'] ?? '')));
if ($code === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Raumcode fehlt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = __DIR__ . '/storage/virtual_rooms.json';
$rooms = [];
if (is_file($file)) {
    $raw = file_get_contents($file);
    $tmp = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($tmp)) $rooms = $tmp;
}

$room = null;
foreach ($rooms as $r) {
    if (strtoupper((string)($r['code'] ?? '')) === $code) {
        $room = $r;
        break;
    }
}
if (!is_array($room)) {
    http_response_code(404);
    echo json_encode(['error' => 'Raum nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$now = time();
$end = strtotime((string)($room['end_at'] ?? '')) ?: 0;
$closed = ((string)($room['status'] ?? 'active') !== 'active') || ($end > 0 && $now >= $end);

$submitted = false;
foreach ((array)($room['participants'] ?? []) as $p) {
    if (strtolower((string)($p['username'] ?? '')) === strtolower((string)$student['username'])) {
        $submitted = trim((string)($p['submitted_at'] ?? '')) !== '';
        break;
    }
}

echo json_encode([
    'room' => [
        'room_id' => (string)($room['room_id'] ?? ''),
        'code' => $code,
        'title' => (string)($room['title'] ?? ''),
        'prompt' => (string)($room['prompt'] ?? ''),
        'end_at' => (string)($room['end_at'] ?? ''),
        'remaining_seconds' => max(0, $end - $now),
        'closed' => $closed,
        'submitted' => $submitted,
    ]
], JSON_UNESCAPED_UNICODE);
