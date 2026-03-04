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
require_once __DIR__ . '/homework_lib.php';
$studentSession = require_student_session_json();

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$studentName = trim((string)($body['student_name'] ?? ''));
$taskPrompt = trim((string)($body['task_prompt'] ?? ''));
$letterText = trim((string)($body['letter_text'] ?? ''));
$writingDurationSeconds = (int)($body['writing_duration_seconds'] ?? 0);
$writingStartedAt = trim((string)($body['writing_started_at'] ?? ''));
$assignmentId = trim((string)($body['assignment_id'] ?? ''));
$requiredPoints = $body['required_points'] ?? [];

if ($letterText === '') {
    http_response_code(400);
    echo json_encode(['error' => 'letter_text darf nicht leer sein.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($letterText) > 12000) {
    http_response_code(413);
    echo json_encode(['error' => 'Der Brieftext ist zu lang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($requiredPoints)) {
    $requiredPoints = [];
}

if ($assignmentId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Keine zugewiesene Aufgabe gefunden. Bitte zuerst eine aktive Aufgabe starten.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$writingDurationSeconds = max(0, min(14400, $writingDurationSeconds));
if ($writingStartedAt !== '' && strtotime($writingStartedAt) === false) {
    $writingStartedAt = '';
}

$requiredPoints = array_values(array_filter(array_map(
    static fn($item) => trim((string)$item),
    $requiredPoints
), static fn($item) => $item !== ''));

$assignmentUpdateWarning = '';
$homeworks = load_homework_assignments();
$assignmentIndex = -1;
$assignment = null;
foreach ($homeworks as $i => $item) {
    if (!is_array($item)) {
        continue;
    }
    if ((string)($item['id'] ?? '') !== $assignmentId) {
        continue;
    }
    $assignmentIndex = $i;
    $assignment = $item;
    break;
}

if (!is_array($assignment) || $assignmentIndex < 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Zuordnung zur Aufgabe fehlgeschlagen: Aufgabe nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$studentUsername = (string)($studentSession['username'] ?? '');
if (!assignment_targets_student($assignment, $studentUsername)) {
    http_response_code(403);
    echo json_encode(['error' => 'Diese Aufgabe ist Ihnen nicht zugewiesen.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!assignment_is_active_now($assignment, time())) {
    http_response_code(400);
    echo json_encode(['error' => 'Diese Aufgabe ist derzeit nicht aktiv.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$assignees = is_array($assignment['assignees'] ?? null) ? $assignment['assignees'] : [];
$state = is_array($assignees[$studentUsername] ?? null) ? $assignees[$studentUsername] : [];

$startedAtState = trim((string)($state['started_at'] ?? ''));
if ($startedAtState === '') {
    $startedAtState = gmdate('c');
    $state['started_at'] = $startedAtState;
}
$deadlineAtState = trim((string)($state['deadline_at'] ?? ''));
if ($deadlineAtState === '') {
    $startedTs = strtotime($startedAtState);
    $baseTs = $startedTs === false ? time() : (int)$startedTs;
    $deadlineAtState = gmdate('c', $baseTs + assignment_duration_minutes($assignment) * 60);
    $state['deadline_at'] = $deadlineAtState;
}

$deadlineTs = strtotime($deadlineAtState);
if ($deadlineTs !== false && time() >= (int)$deadlineTs) {
    http_response_code(409);
    echo json_encode(['error' => 'Die Bearbeitungszeit ist abgelaufen. Eine Abgabe ist nicht mehr möglich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$alreadySubmittedAt = trim((string)($state['submitted_at'] ?? ''));
if ($alreadySubmittedAt !== '') {
    http_response_code(409);
    echo json_encode(['error' => 'Diese Aufgabe wurde bereits abgegeben.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Das Storage-Verzeichnis konnte nicht erstellt werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$suffix = '';
try {
    $suffix = bin2hex(random_bytes(4));
} catch (Throwable $e) {
    $suffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
}
$uploadId = gmdate('YmdHis') . '-' . $suffix;
$createdAt = gmdate('c');
$filePath = $storageDir . '/letters-' . gmdate('Y-m-d') . '.jsonl';

$record = [
    'upload_id' => $uploadId,
    'created_at' => $createdAt,
    'review_status' => 'pending',
    'student_name' => $studentName !== '' ? $studentName : ($studentSession['display_name'] !== '' ? $studentSession['display_name'] : $studentSession['username']),
    'student_username' => $studentSession['username'],
    'teacher_username' => (string)($studentSession['teacher_username'] ?? ''),
    'bamf_code' => normalize_bamf_code((string)($studentSession['bamf_code'] ?? '')),
    'task_prompt' => $taskPrompt,
    'assignment_id' => $assignmentId,
    'required_points' => $requiredPoints,
    'letter_text' => $letterText,
    'writing_duration_seconds' => $writingDurationSeconds,
    'writing_started_at' => $writingStartedAt,
    'meta' => [
        'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ],
];

$line = json_encode($record, JSON_UNESCAPED_UNICODE) . PHP_EOL;
$bytes = @file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);
if ($bytes === false) {
    http_response_code(500);
    echo json_encode(['error' => 'In die Aufzeichnungsdatei konnte nicht geschrieben werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

foreach ($homeworks as $i => $item) {
    if (!is_array($item) || (string)($item['id'] ?? '') !== $assignmentId) {
        continue;
    }
    $assignees = is_array($item['assignees'] ?? null) ? $item['assignees'] : [];
    $state = is_array($assignees[$studentUsername] ?? null) ? $assignees[$studentUsername] : [];
    $state['submitted_at'] = $createdAt;
    $state['last_upload_id'] = $uploadId;
    $state['submission_count'] = (int)($state['submission_count'] ?? 0) + 1;
    if (trim((string)($state['started_at'] ?? '')) === '') {
        $state['started_at'] = $createdAt;
    }
    if (trim((string)($state['deadline_at'] ?? '')) === '') {
        $baseTs = strtotime((string)$state['started_at']);
        $state['deadline_at'] = gmdate('c', (($baseTs === false) ? time() : (int)$baseTs) + assignment_duration_minutes($item) * 60);
    }
    $assignees[$studentUsername] = $state;
    $homeworks[$i]['assignees'] = $assignees;
    $homeworks[$i]['updated_at'] = $createdAt;
    break;
}
if (!write_homework_assignments($homeworks)) {
    $assignmentUpdateWarning = 'Aufgabenstatus konnte nach dem Upload nicht aktualisiert werden.';
} else {
    append_audit_log('homework_submit', [
        'assignment_id' => $assignmentId,
        'upload_id' => $uploadId,
        'username' => $studentUsername,
    ]);
}

echo json_encode([
    'ok' => true,
    'upload_id' => $uploadId,
    'created_at' => $createdAt,
    'file' => basename($filePath),
    'assignment_id' => $assignmentId,
    'warning' => $assignmentUpdateWarning,
], JSON_UNESCAPED_UNICODE);
