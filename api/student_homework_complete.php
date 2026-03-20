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

$student = require_student_session_json();
$username = mb_strtolower(trim((string)($student['username'] ?? '')));

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

$now = time();
if (!assignment_targets_student($assignment, $username)) {
    http_response_code(403);
    echo json_encode(['error' => 'Diese Aufgabe ist Ihnen nicht zugewiesen.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$assignees = is_array($assignment['assignees'] ?? null) ? $assignment['assignees'] : [];
$state = is_array($assignees[$username] ?? null) ? $assignees[$username] : [];
$submittedAt = trim((string)($state['submitted_at'] ?? ''));
if ($submittedAt !== '') {
    echo json_encode([
        'ok' => true,
        'already_submitted' => true,
        'assignment_id' => $assignmentId,
        'submitted_at' => $submittedAt,
        'server_ts' => $now,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$startedAt = trim((string)($state['started_at'] ?? ''));
$deadlineAt = trim((string)($state['deadline_at'] ?? ''));
if ($startedAt === '') {
    $startedAt = gmdate('c', $now);
    $deadlineAt = gmdate('c', $now + assignment_duration_minutes($assignment) * 60);
}

$submittedAt = gmdate('c', $now);
$state['started_at'] = $startedAt;
$state['deadline_at'] = $deadlineAt;
$state['submitted_at'] = $submittedAt;
$state['last_upload_id'] = (string)($state['last_upload_id'] ?? '');
$state['submission_count'] = max(1, (int)($state['submission_count'] ?? 0));

$assignees[$username] = $state;
$items[$idx]['assignees'] = $assignees;
$items[$idx]['updated_at'] = gmdate('c', $now);

if (!write_homework_assignments($items)) {
    http_response_code(500);
    echo json_encode(['error' => 'Aufgabenstatus konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('homework_complete', [
    'assignment_id' => $assignmentId,
    'username' => $username,
]);

echo json_encode([
    'ok' => true,
    'assignment_id' => $assignmentId,
    'submitted_at' => $submittedAt,
    'server_ts' => $now,
], JSON_UNESCAPED_UNICODE);

