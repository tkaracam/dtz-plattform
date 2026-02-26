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
$grades = $body['grades'] ?? [];
if ($code === '' || !is_array($grades)) {
    http_response_code(400);
    echo json_encode(['error' => 'code und grades sind erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = __DIR__ . '/storage/virtual_rooms.json';
$rooms = [];
if (is_file($file)) {
    $rawRooms = file_get_contents($file);
    $tmp = is_string($rawRooms) ? json_decode($rawRooms, true) : null;
    if (is_array($tmp)) $rooms = $tmp;
}

$roomIdx = -1;
foreach ($rooms as $i => $room) {
    if (strtoupper((string)($room['code'] ?? '')) === $code) {
        $roomIdx = $i;
        break;
    }
}
if ($roomIdx < 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Raum nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$participants = is_array($rooms[$roomIdx]['participants'] ?? null) ? $rooms[$roomIdx]['participants'] : [];
$changed = 0;
$map = [];
foreach ($grades as $entry) {
    if (!is_array($entry)) continue;
    $username = strtolower(trim((string)($entry['username'] ?? '')));
    if ($username === '') continue;

    $score = null;
    $scoreRaw = $entry['score'] ?? null;
    if ($scoreRaw !== null && $scoreRaw !== '') {
        if (is_numeric($scoreRaw)) {
            $score = max(0, min(20, (int)$scoreRaw));
        }
    }

    $map[$username] = [
        'teacher_score' => $score,
        'teacher_grade' => mb_substr(trim((string)($entry['grade'] ?? '')), 0, 20),
        'teacher_note' => mb_substr(trim((string)($entry['teacher_note'] ?? '')), 0, 500),
        'graded_at' => gmdate('c'),
    ];
}

if (!$map) {
    http_response_code(400);
    echo json_encode(['error' => 'Keine gueltigen Bewertungsdaten.'], JSON_UNESCAPED_UNICODE);
    exit;
}

foreach ($participants as $i => $p) {
    $username = strtolower((string)($p['username'] ?? ''));
    if (!isset($map[$username])) continue;
    $participants[$i]['teacher_score'] = $map[$username]['teacher_score'];
    $participants[$i]['teacher_grade'] = $map[$username]['teacher_grade'];
    $participants[$i]['teacher_note'] = $map[$username]['teacher_note'];
    $participants[$i]['graded_at'] = $map[$username]['graded_at'];
    $changed += 1;
}

$rooms[$roomIdx]['participants'] = $participants;
if (file_put_contents($file, json_encode(array_values($rooms), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Speichern fehlgeschlagen.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('room_grade_save_batch', [
    'code' => $code,
    'count' => $changed,
]);

echo json_encode(['ok' => true, 'updated' => $changed], JSON_UNESCAPED_UNICODE);
