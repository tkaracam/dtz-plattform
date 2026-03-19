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

function load_homework_attempt_rows(): array
{
    $file = __DIR__ . '/storage/homework_attempts.jsonl';
    if (!is_file($file)) {
        return [];
    }
    $rows = [];
    $handle = @fopen($file, 'rb');
    if (!$handle) {
        return [];
    }
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    fclose($handle);
    return $rows;
}

function load_student_name_map_for_admin(array $admin): array
{
    $map = [];
    foreach (load_student_users() as $student) {
        if (!is_array($student)) {
            continue;
        }
        if (!admin_can_access_student_record($student, $admin)) {
            continue;
        }
        $username = mb_strtolower(trim((string)($student['username'] ?? '')));
        if ($username === '') {
            continue;
        }
        $name = trim((string)($student['display_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($student['name'] ?? ''));
        }
        $map[$username] = $name;
    }
    return $map;
}

$admin = require_admin_role_json(['hauptadmin', 'docent']);
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$assignmentId = trim((string)($body['assignment_id'] ?? ''));
if ($assignmentId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'assignment_id fehlt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$assignment = null;
foreach (load_homework_assignments() as $row) {
    if (!is_array($row)) {
        continue;
    }
    if ((string)($row['id'] ?? '') !== $assignmentId) {
        continue;
    }
    $assignment = $row;
    break;
}

if (!is_array($assignment)) {
    http_response_code(404);
    echo json_encode(['error' => 'Aufgabe nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!assignment_visibility_for_admin($assignment, $admin)) {
    http_response_code(403);
    echo json_encode(['error' => 'Keine Berechtigung für diese Aufgabe.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$assignees = is_array($assignment['assignees'] ?? null) ? $assignment['assignees'] : [];
$allAttempts = load_homework_attempt_rows();
$latestByStudent = [];
foreach ($allAttempts as $row) {
    if (!is_array($row)) {
        continue;
    }
    if ((string)($row['assignment_id'] ?? '') !== $assignmentId) {
        continue;
    }
    $studentUsername = mb_strtolower(trim((string)($row['student_username'] ?? '')));
    if ($studentUsername === '') {
        continue;
    }
    if (!isset($assignees[$studentUsername])) {
        continue;
    }
    $attemptedAt = (string)($row['attempted_at'] ?? '');
    $prev = is_array($latestByStudent[$studentUsername] ?? null) ? $latestByStudent[$studentUsername] : null;
    if (!$prev || strcmp((string)($prev['attempted_at'] ?? ''), $attemptedAt) < 0) {
        $latestByStudent[$studentUsername] = $row;
    }
}

$nameMap = load_student_name_map_for_admin($admin);
$rows = [];
$summary = [
    'assigned_total' => 0,
    'started_total' => 0,
    'submitted_total' => 0,
    'attempted_total' => 0,
    'ontime_total' => 0,
    'late_total' => 0,
    'avg_accuracy_percent' => null,
    'avg_elapsed_seconds' => null,
];

$accuracySum = 0.0;
$accuracyCount = 0;
$elapsedSum = 0;
$elapsedCount = 0;

foreach ($assignees as $studentUsername => $rawState) {
    if (!is_array($rawState)) {
        $rawState = [];
    }
    $state = assignment_state_from_raw($assignment, $rawState);
    $startedAt = (string)($state['started_at'] ?? '');
    $deadlineAt = (string)($state['deadline_at'] ?? '');
    $submittedAt = (string)($state['submitted_at'] ?? '');
    $attempt = is_array($latestByStudent[$studentUsername] ?? null) ? $latestByStudent[$studentUsername] : null;

    $summary['assigned_total']++;
    if ($startedAt !== '') {
        $summary['started_total']++;
    }
    if ($submittedAt !== '') {
        $summary['submitted_total']++;
    }

    $correct = null;
    $wrong = null;
    $total = null;
    $unanswered = null;
    $accuracyPercent = null;
    $elapsedSeconds = null;
    $attemptedAt = '';
    if ($attempt) {
        $summary['attempted_total']++;
        $correct = max(0, (int)($attempt['correct'] ?? 0));
        $wrong = max(0, (int)($attempt['wrong'] ?? 0));
        $total = max(0, (int)($attempt['total'] ?? 0));
        $unanswered = max(0, (int)($attempt['unanswered'] ?? 0));
        $attemptedAt = (string)($attempt['attempted_at'] ?? '');
        $elapsedSeconds = max(0, (int)($attempt['elapsed_seconds'] ?? 0));
        if ($total > 0) {
            $accuracyPercent = (int)round(($correct / $total) * 100);
            $accuracySum += ($correct / $total) * 100;
            $accuracyCount++;
        }
        if ($elapsedSeconds > 0) {
            $elapsedSum += $elapsedSeconds;
            $elapsedCount++;
        }
    }

    $submittedTs = $submittedAt !== '' ? strtotime($submittedAt) : false;
    $deadlineTs = $deadlineAt !== '' ? strtotime($deadlineAt) : false;
    if ($submittedTs !== false && $deadlineTs !== false) {
        if ((int)$submittedTs <= (int)$deadlineTs) {
            $summary['ontime_total']++;
        } else {
            $summary['late_total']++;
        }
    }

    $rows[] = [
        'student_username' => (string)$studentUsername,
        'student_name' => (string)($nameMap[$studentUsername] ?? ''),
        'started_at' => $startedAt,
        'deadline_at' => $deadlineAt,
        'submitted_at' => $submittedAt,
        'attempted_at' => $attemptedAt,
        'correct' => $correct,
        'wrong' => $wrong,
        'total' => $total,
        'unanswered' => $unanswered,
        'accuracy_percent' => $accuracyPercent,
        'elapsed_seconds' => $elapsedSeconds,
    ];
}

if ($accuracyCount > 0) {
    $summary['avg_accuracy_percent'] = (int)round($accuracySum / $accuracyCount);
}
if ($elapsedCount > 0) {
    $summary['avg_elapsed_seconds'] = (int)round($elapsedSum / $elapsedCount);
}

usort($rows, static function (array $a, array $b): int {
    $aSubmitted = trim((string)($a['submitted_at'] ?? '')) !== '';
    $bSubmitted = trim((string)($b['submitted_at'] ?? '')) !== '';
    if ($aSubmitted !== $bSubmitted) {
        return $aSubmitted ? 1 : -1;
    }
    return strcmp((string)($a['student_username'] ?? ''), (string)($b['student_username'] ?? ''));
});

echo json_encode([
    'ok' => true,
    'assignment' => [
        'id' => (string)($assignment['id'] ?? ''),
        'title' => (string)($assignment['title'] ?? ''),
        'course_id' => (string)($assignment['course_id'] ?? ''),
        'teacher_username' => (string)($assignment['teacher_username'] ?? ''),
        'template_id' => (string)($assignment['template_id'] ?? ''),
    ],
    'summary' => $summary,
    'students' => $rows,
], JSON_UNESCAPED_UNICODE);

