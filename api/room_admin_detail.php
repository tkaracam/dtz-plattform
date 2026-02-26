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
require_admin_session_json();

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

$foundIdx = -1;
$found = null;
foreach ($rooms as $i => $room) {
    if (strtoupper((string)($room['code'] ?? '')) !== $code) continue;
    $foundIdx = $i;
    $found = $room;
    break;
}
if (!is_array($found)) {
    http_response_code(404);
    echo json_encode(['error' => 'Raum nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$now = time();
$end = strtotime((string)($found['end_at'] ?? '')) ?: 0;
if (($found['status'] ?? 'active') === 'active' && $end > 0 && $now >= $end) {
    $rooms[$foundIdx]['status'] = 'closed';
    $found['status'] = 'closed';
    @file_put_contents($file, json_encode(array_values($rooms), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX);
}

$participants = [];
foreach ((array)($found['participants'] ?? []) as $p) {
    if (!is_array($p)) continue;
    $participants[] = [
        'username' => (string)($p['username'] ?? ''),
        'display_name' => (string)($p['display_name'] ?? ''),
        'joined_at' => (string)($p['joined_at'] ?? ''),
        'submitted_at' => (string)($p['submitted_at'] ?? ''),
        'word_count' => (int)($p['word_count'] ?? 0),
        'submission_text' => (string)($p['submission_text'] ?? ''),
        'teacher_score' => isset($p['teacher_score']) ? (int)$p['teacher_score'] : null,
        'teacher_grade' => (string)($p['teacher_grade'] ?? ''),
        'teacher_note' => (string)($p['teacher_note'] ?? ''),
        'graded_at' => (string)($p['graded_at'] ?? ''),
    ];
}

usort($participants, static function(array $a, array $b): int {
    return strcmp((string)($a['joined_at'] ?? ''), (string)($b['joined_at'] ?? ''));
});

echo json_encode([
    'room' => [
        'room_id' => (string)($found['room_id'] ?? ''),
        'code' => (string)($found['code'] ?? ''),
        'title' => (string)($found['title'] ?? ''),
        'prompt' => (string)($found['prompt'] ?? ''),
        'created_at' => (string)($found['created_at'] ?? ''),
        'start_at' => (string)($found['start_at'] ?? ''),
        'end_at' => (string)($found['end_at'] ?? ''),
        'status' => (string)($found['status'] ?? 'active'),
        'remaining_seconds' => max(0, $end - $now),
    ],
    'participants' => $participants,
], JSON_UNESCAPED_UNICODE);
