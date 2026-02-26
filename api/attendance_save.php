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

function attendance_file_path(): string
{
    return __DIR__ . '/storage/attendance_sessions.json';
}

function load_attendance_sessions(): array
{
    $file = attendance_file_path();
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function write_attendance_sessions(array $sessions): bool
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode(array_values($sessions), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return is_string($json) && file_put_contents(attendance_file_path(), $json . PHP_EOL, LOCK_EX) !== false;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionId = trim((string)($body['session_id'] ?? ''));
$courseId = trim((string)($body['course_id'] ?? ''));
$courseName = trim((string)($body['course_name'] ?? ''));
$lessonDate = trim((string)($body['lesson_date'] ?? ''));
$teacherNote = trim((string)($body['teacher_note'] ?? ''));
$evidenceNote = trim((string)($body['evidence_note'] ?? ''));
$rows = $body['rows'] ?? [];

if ($courseName === '' || $lessonDate === '') {
    http_response_code(400);
    echo json_encode(['error' => 'course_name und lesson_date sind erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lessonDate)) {
    http_response_code(400);
    echo json_encode(['error' => 'lesson_date muss YYYY-MM-DD sein.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!is_array($rows) || count($rows) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Mindestens ein Teilnehmer ist erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowed = ['present', 'late', 'absent', 'excused'];
$sanitizedRows = [];
foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $username = mb_strtolower(trim((string)($row['username'] ?? '')));
    $status = trim((string)($row['status'] ?? ''));
    $note = trim((string)($row['note'] ?? ''));
    if ($username === '' || !preg_match('/^[a-z0-9._-]{3,32}$/', $username)) continue;
    if (!in_array($status, $allowed, true)) $status = 'present';
    $sanitizedRows[] = [
        'username' => $username,
        'status' => $status,
        'note' => $note,
    ];
}

if (!$sanitizedRows) {
    http_response_code(400);
    echo json_encode(['error' => 'Keine gueltigen Teilnehmerdaten.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessions = load_attendance_sessions();
$idx = -1;
if ($sessionId !== '') {
    foreach ($sessions as $i => $s) {
        if ((string)($s['session_id'] ?? '') === $sessionId) {
            $idx = $i;
            break;
        }
    }
}

if ($idx >= 0 && !empty($sessions[$idx]['locked'])) {
    http_response_code(409);
    echo json_encode(['error' => 'Diese Sitzung ist gesperrt und kann nicht mehr bearbeitet werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$now = gmdate('c');
if ($idx < 0) {
    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $suffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    }
    $sessionId = 'att-' . gmdate('YmdHis') . '-' . $suffix;
    $sessions[] = [
        'session_id' => $sessionId,
        'course_id' => $courseId,
        'course_name' => $courseName,
        'lesson_date' => $lessonDate,
        'teacher_note' => $teacherNote,
        'evidence_note' => $evidenceNote,
        'rows' => $sanitizedRows,
        'locked' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ];
} else {
    $sessions[$idx]['course_name'] = $courseName;
    $sessions[$idx]['course_id'] = $courseId;
    $sessions[$idx]['lesson_date'] = $lessonDate;
    $sessions[$idx]['teacher_note'] = $teacherNote;
    $sessions[$idx]['evidence_note'] = $evidenceNote;
    $sessions[$idx]['rows'] = $sanitizedRows;
    $sessions[$idx]['updated_at'] = $now;
}

if (!write_attendance_sessions($sessions)) {
    http_response_code(500);
    echo json_encode(['error' => 'Yoklama konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('attendance_save', [
    'session_id' => $sessionId,
    'course_id' => $courseId,
    'course_name' => $courseName,
    'rows' => count($sanitizedRows),
]);

echo json_encode(['ok' => true, 'session_id' => $sessionId], JSON_UNESCAPED_UNICODE);
