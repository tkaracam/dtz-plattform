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
$username = strtolower(trim((string)($body['username'] ?? '')));
$scoreRaw = $body['score'] ?? null;
$grade = trim((string)($body['grade'] ?? ''));
$note = trim((string)($body['teacher_note'] ?? ''));

if ($code === '' || $username === '') {
    http_response_code(400);
    echo json_encode(['error' => 'code und username sind erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$score = null;
if ($scoreRaw !== null && $scoreRaw !== '') {
    if (!is_numeric($scoreRaw)) {
        http_response_code(400);
        echo json_encode(['error' => 'score muss numerisch sein.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $score = max(0, min(20, (int)$scoreRaw));
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
$found = false;
foreach ($participants as $i => $p) {
    if (strtolower((string)($p['username'] ?? '')) !== $username) continue;
    $participants[$i]['teacher_score'] = $score;
    $participants[$i]['teacher_grade'] = mb_substr($grade, 0, 20);
    $participants[$i]['teacher_note'] = mb_substr($note, 0, 500);
    $participants[$i]['graded_at'] = gmdate('c');
    $found = true;
    break;
}
if (!$found) {
    http_response_code(404);
    echo json_encode(['error' => 'Teilnehmer nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rooms[$roomIdx]['participants'] = $participants;
if (file_put_contents($file, json_encode(array_values($rooms), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Speichern fehlgeschlagen.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('room_grade_save', [
    'code' => $code,
    'username' => $username,
    'score' => $score,
    'grade' => $grade,
]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
