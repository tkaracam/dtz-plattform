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
    echo json_encode(['error' => 'Nur POST wird unterstützt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/homework_lib.php';

function homework_attempts_file_path(): string
{
    return __DIR__ . '/storage/homework_attempts.jsonl';
}

function append_homework_attempt_row(array $row): bool
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode($row, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return false;
    }
    return file_put_contents(homework_attempts_file_path(), $json . "\n", FILE_APPEND | LOCK_EX) !== false;
}

function parse_assignment_dtz_template(string $templateId): ?array
{
    if (preg_match('/^dtz\-(hoeren|lesen)\-teil([1-5])\-fragenpaket$/', mb_strtolower(trim($templateId)), $m) !== 1) {
        return null;
    }
    $module = (string)$m[1];
    $teil = (int)$m[2];
    if ($module === 'hoeren' && ($teil < 1 || $teil > 4)) {
        return null;
    }
    if ($module === 'lesen' && ($teil < 1 || $teil > 5)) {
        return null;
    }
    return ['module' => $module, 'teil' => $teil];
}

$student = require_student_session_json();
$username = mb_strtolower(trim((string)($student['username'] ?? '')));
if ($username === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht autorisiert.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$assignmentId = trim((string)($body['assignment_id'] ?? ''));
$module = mb_strtolower(trim((string)($body['module'] ?? '')));
$teil = (int)($body['teil'] ?? 0);
$correct = max(0, (int)($body['correct'] ?? 0));
$wrong = max(0, (int)($body['wrong'] ?? 0));
$unanswered = max(0, (int)($body['unanswered'] ?? 0));
$total = max(0, (int)($body['total'] ?? 0));
$points = max(0, (int)($body['points'] ?? 0));
$maxPoints = max(0, (int)($body['max_points'] ?? 0));

if ($assignmentId === '' || $total <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'assignment_id und gültige Ergebniswerte sind erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!in_array($module, ['hoeren', 'lesen'], true) || $teil < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiger DTZ-Teil.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = load_homework_assignments();
$idx = -1;
$assignment = null;
foreach ($items as $i => $row) {
    if (!is_array($row)) {
        continue;
    }
    if ((string)($row['id'] ?? '') !== $assignmentId) {
        continue;
    }
    $idx = $i;
    $assignment = $row;
    break;
}

if (!is_array($assignment) || $idx < 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Aufgabe nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!assignment_targets_student($assignment, $username)) {
    http_response_code(403);
    echo json_encode(['error' => 'Diese Aufgabe ist Ihnen nicht zugewiesen.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$template = parse_assignment_dtz_template((string)($assignment['template_id'] ?? ''));
if (!is_array($template)) {
    http_response_code(400);
    echo json_encode(['error' => 'Diese Aufgabe ist keine DTZ-Teil-Aufgabe.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ((string)$template['module'] !== $module || (int)$template['teil'] !== $teil) {
    http_response_code(400);
    echo json_encode(['error' => 'Das Ergebnis passt nicht zur zugewiesenen Aufgabe.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$now = time();
if (!assignment_is_active_now($assignment, $now)) {
    http_response_code(400);
    echo json_encode(['error' => 'Die Aufgabe ist derzeit nicht aktiv.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$assignees = is_array($assignment['assignees'] ?? null) ? $assignment['assignees'] : [];
$state = is_array($assignees[$username] ?? null) ? $assignees[$username] : [];
$state = assignment_state_from_raw($assignment, $state);
$startedAt = trim((string)($state['started_at'] ?? ''));
$submittedAt = trim((string)($state['submitted_at'] ?? ''));
$deadlineAt = trim((string)($state['deadline_at'] ?? ''));

if ($submittedAt !== '') {
    http_response_code(409);
    echo json_encode(['error' => 'Die Aufgabe wurde bereits abgegeben.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($startedAt === '') {
    $startedAt = gmdate('c', $now);
    $deadlineAt = gmdate('c', $now + assignment_duration_minutes($assignment) * 60);
    $state['started_at'] = $startedAt;
    $state['deadline_at'] = $deadlineAt;
}

$startedTs = strtotime($startedAt);
$deadlineTs = $deadlineAt !== '' ? strtotime($deadlineAt) : false;
$isLate = ($deadlineTs !== false && $now > (int)$deadlineTs);
if ($isLate) {
    http_response_code(409);
    echo json_encode(['error' => 'Die Frist ist abgelaufen.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$elapsed = $startedTs === false ? 0 : max(0, $now - (int)$startedTs);
$withinDeadline = ($deadlineTs === false) ? true : ($now <= (int)$deadlineTs);

try {
    $attemptSuffix = bin2hex(random_bytes(4));
} catch (Throwable $e) {
    $attemptSuffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
}
$attemptId = 'hwa-' . gmdate('YmdHis') . '-' . $attemptSuffix;

$attemptRow = [
    'attempt_id' => $attemptId,
    'attempted_at' => gmdate('c', $now),
    'assignment_id' => $assignmentId,
    'teacher_username' => (string)($assignment['teacher_username'] ?? ''),
    'course_id' => (string)($assignment['course_id'] ?? ''),
    'student_username' => $username,
    'module' => $module,
    'teil' => $teil,
    'correct' => $correct,
    'wrong' => $wrong,
    'unanswered' => $unanswered,
    'total' => $total,
    'points' => $points,
    'max_points' => $maxPoints,
    'elapsed_seconds' => $elapsed,
    'within_deadline' => $withinDeadline,
];

if (!append_homework_attempt_row($attemptRow)) {
    http_response_code(500);
    echo json_encode(['error' => 'Ergebnis konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$submittedNow = $unanswered <= 0;
$state['submission_count'] = (int)($state['submission_count'] ?? 0);
$state['last_upload_id'] = (string)($state['last_upload_id'] ?? '');

if ($submittedNow) {
    $state['submitted_at'] = gmdate('c', $now);
    $state['submission_count'] = $state['submission_count'] + 1;
    $state['last_upload_id'] = $attemptId;
}

$state['last_dtz_result'] = [
    'attempt_id' => $attemptId,
    'module' => $module,
    'teil' => $teil,
    'correct' => $correct,
    'wrong' => $wrong,
    'unanswered' => $unanswered,
    'total' => $total,
    'points' => $points,
    'max_points' => $maxPoints,
    'elapsed_seconds' => $elapsed,
    'saved_at' => gmdate('c', $now),
];

$assignees[$username] = $state;
$items[$idx]['assignees'] = $assignees;
$items[$idx]['updated_at'] = gmdate('c', $now);

if (!write_homework_assignments($items)) {
    http_response_code(500);
    echo json_encode(['error' => 'Aufgabenstatus konnte nicht aktualisiert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('homework_dtz_result_submit', [
    'assignment_id' => $assignmentId,
    'student_username' => $username,
    'module' => $module,
    'teil' => $teil,
    'correct' => $correct,
    'total' => $total,
    'unanswered' => $unanswered,
    'submitted' => $submittedNow,
]);

echo json_encode([
    'ok' => true,
    'submitted' => $submittedNow,
    'attempt_id' => $attemptId,
    'elapsed_seconds' => $elapsed,
    'within_deadline' => $withinDeadline,
], JSON_UNESCAPED_UNICODE);
