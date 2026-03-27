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
    require_once __DIR__ . '/auth.php';
    api_error(405, 'method_not_allowed', 'Nur POST wird unterstützt.');
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/homework_lib.php';

$student = require_student_session_json();
$username = mb_strtolower(trim((string)($student['username'] ?? '')));

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    api_error(400, 'invalid_json', 'Ungültiges JSON wurde gesendet.');
}

$assignmentId = trim((string)($body['assignment_id'] ?? ''));
if ($assignmentId === '') {
    api_error(400, 'assignment_id_missing', 'assignment_id fehlt.');
}

$txn = [
    'http' => 200,
    'err' => '',
    'started_at' => '',
    'deadline_at' => '',
    'audit' => false,
];

$mutateOk = homework_assignments_mutate(function (array $items) use ($assignmentId, $username, &$txn): array|false {
    $now = time();
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
    if (!assignment_targets_student($assignment, $username)) {
        $txn['http'] = 403;
        $txn['err'] = 'Diese Aufgabe ist Ihnen nicht zugewiesen.';
        return false;
    }
    if (!assignment_is_active_now($assignment, $now)) {
        $txn['http'] = 400;
        $txn['err'] = 'Die Aufgabe ist derzeit nicht aktiv.';
        return false;
    }

    $assignees = is_array($assignment['assignees'] ?? null) ? $assignment['assignees'] : [];
    $state = is_array($assignees[$username] ?? null) ? $assignees[$username] : [];
    $startedAt = trim((string)($state['started_at'] ?? ''));
    $submittedAt = trim((string)($state['submitted_at'] ?? ''));
    $deadlineAt = trim((string)($state['deadline_at'] ?? ''));

    if ($submittedAt !== '') {
        $txn['http'] = 409;
        $txn['err'] = 'Die Aufgabe wurde bereits abgegeben.';
        return false;
    }

    if ($startedAt !== '') {
        $txn['started_at'] = $startedAt;
        $txn['deadline_at'] = $deadlineAt;
        return false;
    }

    $startedAt = gmdate('c');
    $deadlineAt = gmdate('c', $now + assignment_duration_minutes($assignment) * 60);
    $state['started_at'] = $startedAt;
    $state['deadline_at'] = $deadlineAt;
    $state['submitted_at'] = '';
    $state['last_upload_id'] = (string)($state['last_upload_id'] ?? '');
    $state['submission_count'] = (int)($state['submission_count'] ?? 0);
    $assignees[$username] = $state;
    $items[$idx]['assignees'] = $assignees;
    $items[$idx]['updated_at'] = gmdate('c');

    $txn['started_at'] = $startedAt;
    $txn['deadline_at'] = $deadlineAt;
    $txn['audit'] = true;
    return $items;
});

if (!$mutateOk) {
    api_error(500, 'homework_start_store_failed', 'Startzeit konnte nicht gespeichert werden.');
}
if ($txn['http'] !== 200) {
    api_error((int)$txn['http'], 'homework_start_rejected', (string)$txn['err']);
}

if ($txn['audit']) {
    append_audit_log('homework_start', [
        'assignment_id' => $assignmentId,
        'username' => $username,
    ]);
}

$startedAt = (string)$txn['started_at'];
$deadlineAt = (string)$txn['deadline_at'];
$deadlineTs = $deadlineAt !== '' ? strtotime($deadlineAt) : false;
$remaining = ($deadlineTs === false) ? 0 : max(0, (int)$deadlineTs - time());

api_ok('homework_started', 'Hausaufgabe gestartet.', [
    'server_ts' => time(),
    'assignment_id' => $assignmentId,
    'started_at' => $startedAt,
    'deadline_at' => $deadlineAt,
    'remaining_seconds' => $remaining,
]);
