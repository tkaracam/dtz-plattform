<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur GET wird unterstuetzt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/homework_lib.php';

$student = require_student_session_json();
$username = mb_strtolower(trim((string)($student['username'] ?? '')));
$now = time();
$assignments = load_homework_assignments();

$current = pick_current_assignment_for_student($assignments, $username, $now);
if (!is_array($current)) {
    $nextPlanned = null;
    foreach ($assignments as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!assignment_targets_student($row, $username)) {
            continue;
        }
        $status = mb_strtolower(trim((string)($row['status'] ?? 'active')));
        if ($status !== 'active') {
            continue;
        }
        $state = assignment_user_state($row, $username);
        if (trim((string)($state['submitted_at'] ?? '')) !== '') {
            continue;
        }
        $startTs = assignment_availability_ts($row);
        if ($startTs <= 0 || $startTs <= $now) {
            continue;
        }
        if (!is_array($nextPlanned) || $startTs < (int)($nextPlanned['start_ts'] ?? PHP_INT_MAX)) {
            $nextPlanned = [
                'assignment' => $row,
                'state' => $state,
                'start_ts' => $startTs,
            ];
        }
    }
    if (is_array($nextPlanned)) {
        $current = [
            'assignment' => (array)$nextPlanned['assignment'],
            'state' => (array)$nextPlanned['state'],
            'planned_only' => true,
        ];
    }
}

if (!is_array($current)) {
    echo json_encode([
        'has_assignment' => false,
        'message' => 'Derzeit ist keine zugewiesene Aufgabe aktiv.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$assignment = (array)$current['assignment'];
$state = (array)$current['state'];
$plannedOnly = !empty($current['planned_only']);
$deadlineTs = (int)($state['deadline_ts'] ?? 0);
$submittedAt = (string)($state['submitted_at'] ?? '');
$startedAt = (string)($state['started_at'] ?? '');
$startsAt = (string)($assignment['starts_at'] ?? '');
$startsAtTs = $startsAt !== '' ? strtotime($startsAt) : false;
$notActiveYet = (!$startedAt && $startsAtTs !== false && (int)$startsAtTs > $now);

$remaining = 0;
if ($deadlineTs > 0) {
    $remaining = max(0, $deadlineTs - $now);
}
$expired = ($submittedAt === '' && $deadlineTs > 0 && $now >= $deadlineTs);
$locked = ($submittedAt !== '') || $expired || $notActiveYet;
$reminder = homework_reminder_for_state($state, $now);

$response = [
    'has_assignment' => true,
    'assignment' => [
        'id' => (string)($assignment['id'] ?? ''),
        'title' => (string)($assignment['title'] ?? ''),
        'description' => (string)($assignment['description'] ?? ''),
        'attachment' => (string)($assignment['attachment'] ?? ''),
        'duration_minutes' => (int)($assignment['duration_minutes'] ?? 0),
        'starts_at' => (string)($assignment['starts_at'] ?? ''),
        'status' => (string)($assignment['status'] ?? 'active'),
        'created_at' => (string)($assignment['created_at'] ?? ''),
        'target_label' => (string)($assignment['target_label'] ?? ''),
    ],
    'state' => [
        'started_at' => $startedAt,
        'deadline_at' => (string)($state['deadline_at'] ?? ''),
        'submitted_at' => $submittedAt,
        'last_upload_id' => (string)($state['last_upload_id'] ?? ''),
        'remaining_seconds' => $remaining,
        'expired' => $expired,
        'locked' => $locked,
        'not_active_yet' => $notActiveYet,
        'planned_only' => $plannedOnly,
        'can_start' => (!$notActiveYet && $startedAt === '' && $submittedAt === ''),
        'can_submit' => (!$locked && $startedAt !== ''),
        'reminder_level' => (string)($reminder['level'] ?? 'none'),
        'reminder_label' => (string)($reminder['label'] ?? 'Keine Fristwarnung'),
        'reminder_urgent' => !empty($reminder['urgent']),
    ],
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
