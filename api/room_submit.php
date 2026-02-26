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
$student = require_student_session_json();

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$code = strtoupper(trim((string)($body['code'] ?? '')));
$text = trim((string)($body['text'] ?? ''));
if ($code === '' || $text === '') {
    http_response_code(400);
    echo json_encode(['error' => 'code und text sind erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = __DIR__ . '/storage/virtual_rooms.json';
$rooms = [];
if (is_file($file)) {
    $rawRooms = file_get_contents($file);
    $tmp = is_string($rawRooms) ? json_decode($rawRooms, true) : null;
    if (is_array($tmp)) $rooms = $tmp;
}

$idx = -1;
foreach ($rooms as $i => $r) {
    if (strtoupper((string)($r['code'] ?? '')) === $code) { $idx = $i; break; }
}
if ($idx < 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Raum nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$room = $rooms[$idx];
$now = time();
$end = strtotime((string)($room['end_at'] ?? '')) ?: 0;
$closed = ((string)($room['status'] ?? 'active') !== 'active') || ($end > 0 && $now >= $end);
if ($closed) {
    $rooms[$idx]['status'] = 'closed';
    @file_put_contents($file, json_encode(array_values($rooms), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX);
    http_response_code(403);
    echo json_encode(['error' => 'Raum ist geschlossen.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$username = strtolower((string)$student['username']);
$participants = is_array($room['participants'] ?? null) ? $room['participants'] : [];
$found = false;
foreach ($participants as $i => $p) {
    if (strtolower((string)($p['username'] ?? '')) !== $username) continue;
    $participants[$i]['submitted_at'] = gmdate('c');
    $participants[$i]['submission_text'] = mb_substr($text, 0, 12000);
    $participants[$i]['word_count'] = (int)preg_match_all('/\S+/u', $text, $m);
    $found = true;
    break;
}
if (!$found) {
    http_response_code(403);
    echo json_encode(['error' => 'Sie sind nicht in diesem Raum registriert.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rooms[$idx]['participants'] = $participants;
if (file_put_contents($file, json_encode(array_values($rooms), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Speichern fehlgeschlagen.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('room_submit', [
    'room_id' => (string)($room['room_id'] ?? ''),
    'username' => (string)$student['username'],
    'word_count' => (int)preg_match_all('/\S+/u', $text, $m),
]);

echo json_encode(['ok' => true, 'submitted_at' => gmdate('c')], JSON_UNESCAPED_UNICODE);
