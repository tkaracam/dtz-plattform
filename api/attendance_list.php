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

function attendance_file_path_list(): string
{
    return __DIR__ . '/storage/attendance_sessions.json';
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = isset($body['limit']) && is_numeric($body['limit']) ? (int)$body['limit'] : 50;
$limit = max(1, min(200, $limit));
$dateFrom = trim((string)($body['date_from'] ?? ''));
$dateTo = trim((string)($body['date_to'] ?? ''));
$courseQuery = mb_strtolower(trim((string)($body['course_query'] ?? '')));
$courseIdFilter = trim((string)($body['course_id'] ?? ''));

$sessions = [];
$file = attendance_file_path_list();
if (is_file($file)) {
    $rawData = file_get_contents($file);
    $tmp = is_string($rawData) ? json_decode($rawData, true) : null;
    if (is_array($tmp)) $sessions = $tmp;
}

$from = $dateFrom !== '' ? strtotime($dateFrom . ' 00:00:00') : null;
$to = $dateTo !== '' ? strtotime($dateTo . ' 23:59:59') : null;
$out = [];

foreach ($sessions as $session) {
    if (!is_array($session)) continue;
    $lessonDate = (string)($session['lesson_date'] ?? '');
    $ts = $lessonDate !== '' ? strtotime($lessonDate . ' 12:00:00') : false;
    if ($from !== null && $ts !== false && $ts < $from) continue;
    if ($to !== null && $ts !== false && $ts > $to) continue;
    $course = (string)($session['course_name'] ?? '');
    $courseId = (string)($session['course_id'] ?? '');
    if ($courseIdFilter !== '' && $courseId !== $courseIdFilter) continue;
    if ($courseQuery !== '' && !str_contains(mb_strtolower($course), $courseQuery)) continue;

    $rows = is_array($session['rows'] ?? null) ? $session['rows'] : [];
    $present = 0; $late = 0; $absent = 0; $excused = 0;
    foreach ($rows as $r) {
        $status = (string)($r['status'] ?? '');
        if ($status === 'present') $present++;
        elseif ($status === 'late') $late++;
        elseif ($status === 'absent') $absent++;
        elseif ($status === 'excused') $excused++;
    }
    $total = count($rows);
    $absenceRate = $total > 0 ? round((($absent + $excused) / $total) * 100, 1) : 0.0;

    $out[] = [
        'session_id' => (string)($session['session_id'] ?? ''),
        'course_name' => $course,
        'course_id' => $courseId,
        'lesson_date' => $lessonDate,
        'locked' => !empty($session['locked']),
        'teacher_note' => (string)($session['teacher_note'] ?? ''),
        'evidence_note' => (string)($session['evidence_note'] ?? ''),
        'present' => $present,
        'late' => $late,
        'absent' => $absent,
        'excused' => $excused,
        'total' => $total,
        'absence_rate' => $absenceRate,
        'updated_at' => (string)($session['updated_at'] ?? ''),
    ];
}

usort($out, static function (array $a, array $b): int {
    return strcmp((string)($b['lesson_date'] ?? ''), (string)($a['lesson_date'] ?? ''));
});
if (count($out) > $limit) $out = array_slice($out, 0, $limit);

echo json_encode(['records' => $out], JSON_UNESCAPED_UNICODE);
