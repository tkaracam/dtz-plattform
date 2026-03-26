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

function payload_required_non_negative_int(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    $value = filter_var($body[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($value === false) {
        return null;
    }
    return (int)$value;
}

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
$correct = payload_required_non_negative_int($body, 'correct');
$wrong = payload_required_non_negative_int($body, 'wrong');
$unanswered = payload_required_non_negative_int($body, 'unanswered');
$total = payload_required_non_negative_int($body, 'total');
$points = payload_required_non_negative_int($body, 'points');
$maxPoints = payload_required_non_negative_int($body, 'max_points');
if ($correct === null || $wrong === null || $unanswered === null || $total === null || $points === null || $maxPoints === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Ergebniswerte: correct/wrong/unanswered/total/points/max_points müssen nicht-negative Ganzzahlen sein.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($total > 200 || $maxPoints > 200) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Ergebniswerte: total/max_points außerhalb des erlaubten Bereichs.'], JSON_UNESCAPED_UNICODE);
    exit;
}

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
if ($correct > $total || $wrong > $total || $unanswered > $total) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Ergebniswerte: Teilwerte dürfen nicht größer als total sein.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (($correct + $wrong + $unanswered) !== $total) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Ergebniswerte: correct + wrong + unanswered muss total entsprechen.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($maxPoints > 0 && $points > $maxPoints) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Punkte: points darf max_points nicht überschreiten.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($maxPoints <= 0 && $points > 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Punkte ohne max_points.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $attemptSuffix = bin2hex(random_bytes(4));
} catch (Throwable $e) {
    $attemptSuffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
}
$attemptId = 'hwa-' . gmdate('YmdHis') . '-' . $attemptSuffix;

$txn = [
    'http' => 200,
    'err' => '',
    'now' => time(),
    'elapsed' => 0,
    'within_deadline' => true,
    'submitted_now' => false,
    'attempt_id' => $attemptId,
    'teacher_username' => '',
    'course_id' => '',
];

$mutateOk = homework_assignments_mutate(function (array $items) use (
    $assignmentId,
    $username,
    $module,
    $teil,
    $correct,
    $wrong,
    $unanswered,
    $total,
    $points,
    $maxPoints,
    &$txn,
    $attemptId
): array|false {
    $now = time();
    $txn['now'] = $now;
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
        $txn['http'] = 404;
        $txn['err'] = 'Aufgabe nicht gefunden.';
        return false;
    }
    $txn['teacher_username'] = (string)($assignment['teacher_username'] ?? '');
    $txn['course_id'] = (string)($assignment['course_id'] ?? '');
    if (!assignment_targets_student($assignment, $username)) {
        $txn['http'] = 403;
        $txn['err'] = 'Diese Aufgabe ist Ihnen nicht zugewiesen.';
        return false;
    }

    $template = parse_assignment_dtz_template((string)($assignment['template_id'] ?? ''));
    if (!is_array($template)) {
        $txn['http'] = 400;
        $txn['err'] = 'Diese Aufgabe ist keine DTZ-Teil-Aufgabe.';
        return false;
    }
    if ((string)$template['module'] !== $module || (int)$template['teil'] !== $teil) {
        $txn['http'] = 400;
        $txn['err'] = 'Das Ergebnis passt nicht zur zugewiesenen Aufgabe.';
        return false;
    }

    if (!assignment_is_active_now($assignment, $now)) {
        $txn['http'] = 400;
        $txn['err'] = 'Die Aufgabe ist derzeit nicht aktiv.';
        return false;
    }
    $assignees = is_array($assignment['assignees'] ?? null) ? $assignment['assignees'] : [];
    $state = is_array($assignees[$username] ?? null) ? $assignees[$username] : [];
    $state = assignment_state_from_raw($assignment, $state);
    $startedAt = trim((string)($state['started_at'] ?? ''));
    $submittedAt = trim((string)($state['submitted_at'] ?? ''));
    $deadlineAt = trim((string)($state['deadline_at'] ?? ''));

    if ($submittedAt !== '') {
        $txn['http'] = 409;
        $txn['err'] = 'Die Aufgabe wurde bereits abgegeben.';
        return false;
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
        $txn['http'] = 409;
        $txn['err'] = 'Die Frist ist abgelaufen.';
        return false;
    }
    $elapsed = $startedTs === false ? 0 : max(0, $now - (int)$startedTs);
    $withinDeadline = ($deadlineTs === false) ? true : ($now <= (int)$deadlineTs);
    $txn['elapsed'] = $elapsed;
    $txn['within_deadline'] = $withinDeadline;

    $submittedNow = $unanswered <= 0;
    $txn['submitted_now'] = $submittedNow;
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

    return $items;
});

if (!$mutateOk) {
    if ($txn['http'] !== 200) {
        http_response_code((int)$txn['http']);
        echo json_encode(['error' => (string)$txn['err']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(500);
    echo json_encode(['error' => 'Aufgabenstatus konnte nicht aktualisiert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$now = (int)$txn['now'];
$elapsed = (int)$txn['elapsed'];
$withinDeadline = (bool)$txn['within_deadline'];
$submittedNow = (bool)$txn['submitted_now'];

$attemptRow = [
    'attempt_id' => $attemptId,
    'attempted_at' => gmdate('c', $now),
    'assignment_id' => $assignmentId,
    'teacher_username' => (string)($txn['teacher_username'] ?? ''),
    'course_id' => (string)($txn['course_id'] ?? ''),
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
