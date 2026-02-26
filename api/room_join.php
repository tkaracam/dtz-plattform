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

$found = null;
$foundIdx = -1;
$now = time();
foreach ($rooms as $i => $room) {
    if (strtoupper((string)($room['code'] ?? '')) !== $code) continue;
    $found = $room;
    $foundIdx = $i;
    break;
}

if (!is_array($found)) {
    http_response_code(404);
    echo json_encode(['error' => 'Raum nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$end = strtotime((string)($found['end_at'] ?? '')) ?: 0;
$status = (string)($found['status'] ?? 'active');
if ($status !== 'active' || ($end > 0 && $now >= $end)) {
    $rooms[$foundIdx]['status'] = 'closed';
    @file_put_contents($file, json_encode(array_values($rooms), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX);
    http_response_code(403);
    echo json_encode(['error' => 'Raum ist geschlossen.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$username = (string)$student['username'];
$displayName = $student['display_name'] !== '' ? (string)$student['display_name'] : $username;
$participants = is_array($found['participants'] ?? null) ? $found['participants'] : [];
$exists = false;
foreach ($participants as $p) {
    if (strtolower((string)($p['username'] ?? '')) === strtolower($username)) {
        $exists = true;
        break;
    }
}
if (!$exists) {
    $participants[] = [
        'username' => $username,
        'display_name' => $displayName,
        'joined_at' => gmdate('c'),
        'submitted_at' => '',
        'submission_text' => '',
        'word_count' => 0,
        'teacher_score' => null,
        'teacher_grade' => '',
        'teacher_note' => '',
        'graded_at' => '',
    ];
    $rooms[$foundIdx]['participants'] = $participants;
    @file_put_contents($file, json_encode(array_values($rooms), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX);
    append_audit_log('room_join', ['room_id' => (string)($found['room_id'] ?? ''), 'username' => $username]);
}

$remaining = max(0, $end - $now);

echo json_encode([
    'ok' => true,
    'room' => [
        'room_id' => (string)($found['room_id'] ?? ''),
        'code' => $code,
        'title' => (string)($found['title'] ?? ''),
        'prompt' => (string)($found['prompt'] ?? ''),
        'end_at' => (string)($found['end_at'] ?? ''),
        'remaining_seconds' => $remaining,
    ],
], JSON_UNESCAPED_UNICODE);
