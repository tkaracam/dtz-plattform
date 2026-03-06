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

$admin = require_admin_session_json();
$now = time();
$items = load_homework_assignments();
$out = [];

foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    if (!assignment_visibility_for_admin($item, $admin)) {
        continue;
    }

    $assignees = is_array($item['assignees'] ?? null) ? $item['assignees'] : [];
    $total = count($assignees);
    $started = 0;
    $submitted = 0;
    $expired = 0;

    foreach ($assignees as $state) {
        if (!is_array($state)) {
            continue;
        }
        $startedAt = trim((string)($state['started_at'] ?? ''));
        $deadlineAt = trim((string)($state['deadline_at'] ?? ''));
        $submittedAt = trim((string)($state['submitted_at'] ?? ''));
        if ($startedAt !== '') {
            $started++;
        }
        if ($submittedAt !== '') {
            $submitted++;
            continue;
        }
        if ($deadlineAt !== '') {
            $deadlineTs = strtotime($deadlineAt);
            if ($deadlineTs !== false && $now >= (int)$deadlineTs) {
                $expired++;
            }
        }
    }

    $out[] = [
        'id' => (string)($item['id'] ?? ''),
        'title' => (string)($item['title'] ?? ''),
        'description' => (string)($item['description'] ?? ''),
        'attachment' => (string)($item['attachment'] ?? ''),
        'target_type' => (string)($item['target_type'] ?? ''),
        'target_label' => (string)($item['target_label'] ?? ''),
        'course_id' => (string)($item['course_id'] ?? ''),
        'duration_minutes' => (int)($item['duration_minutes'] ?? 0),
        'starts_at' => (string)($item['starts_at'] ?? ''),
        'status' => (string)($item['status'] ?? 'active'),
        'teacher_username' => (string)($item['teacher_username'] ?? ''),
        'created_at' => (string)($item['created_at'] ?? ''),
        'updated_at' => (string)($item['updated_at'] ?? ''),
        'assigned_total' => $total,
        'started_total' => $started,
        'submitted_total' => $submitted,
        'expired_total' => $expired,
    ];
}

usort($out, static function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

echo json_encode(['assignments' => $out], JSON_UNESCAPED_UNICODE);
