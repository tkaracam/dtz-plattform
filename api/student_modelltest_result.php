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
if ($assignmentId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'assignment_id ist erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hoerenCorrect = max(0, (int)($body['hoeren_correct'] ?? 0));
$hoerenTotal = max(0, (int)($body['hoeren_total'] ?? 0));
$lesenCorrect = max(0, (int)($body['lesen_correct'] ?? 0));
$lesenTotal = max(0, (int)($body['lesen_total'] ?? 0));
$schreibenScore = max(0, (int)($body['schreiben_score'] ?? 0));
$schreibenMax = max(0, (int)($body['schreiben_max'] ?? 0));
$overallPercent = max(0, min(100, (int)($body['overall_percent'] ?? 0)));
$level = trim((string)($body['level'] ?? ''));

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

$templateId = mb_strtolower(trim((string)($assignment['template_id'] ?? '')));
if ($templateId !== 'dtz-mock-pruefung-komplett') {
    http_response_code(400);
    echo json_encode(['error' => 'Diese Aufgabe ist keine Modelltest-Aufgabe.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$assignees = is_array($assignment['assignees'] ?? null) ? $assignment['assignees'] : [];
$state = is_array($assignees[$username] ?? null) ? $assignees[$username] : [];
$nowIso = gmdate('c');

$state['last_modelltest_result'] = [
    'saved_at' => $nowIso,
    'hoeren_correct' => $hoerenCorrect,
    'hoeren_total' => $hoerenTotal,
    'lesen_correct' => $lesenCorrect,
    'lesen_total' => $lesenTotal,
    'schreiben_score' => $schreibenScore,
    'schreiben_max' => $schreibenMax,
    'overall_percent' => $overallPercent,
    'level' => $level !== '' ? $level : 'A1'
];

$assignees[$username] = $state;
$items[$idx]['assignees'] = $assignees;
$items[$idx]['updated_at'] = $nowIso;

if (!write_homework_assignments($items)) {
    http_response_code(500);
    echo json_encode(['error' => 'Modelltest-Ergebnis konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('student_modelltest_result_saved', [
    'assignment_id' => $assignmentId,
    'student_username' => $username,
    'overall_percent' => $overallPercent,
    'level' => $level !== '' ? $level : 'A1'
]);

echo json_encode([
    'ok' => true,
    'saved_at' => $nowIso
], JSON_UNESCAPED_UNICODE);

