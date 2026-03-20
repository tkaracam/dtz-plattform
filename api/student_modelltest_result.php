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

function modelltest_level_from_percent_local(int $percent): string
{
    $p = max(0, min(100, $percent));
    if ($p >= 75) {
        return 'B1';
    }
    if ($p >= 50) {
        return 'A2';
    }
    return 'A1';
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
if ($hoerenTotal <= 0 || $lesenTotal <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Modelltest-Teile: Hören und Lesen müssen > 0 sein.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($hoerenCorrect > $hoerenTotal || $lesenCorrect > $lesenTotal) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Modelltest-Werte: richtig > gesamt.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($schreibenMax > 0 && $schreibenScore > $schreibenMax) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Schreibpunkte: score > max.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($schreibenMax <= 0 && $schreibenScore > 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Schreibpunkte ohne Maximalwert.'], JSON_UNESCAPED_UNICODE);
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

$templateId = mb_strtolower(trim((string)($assignment['template_id'] ?? '')));
if ($templateId !== 'dtz-mock-pruefung-komplett') {
    http_response_code(400);
    echo json_encode(['error' => 'Diese Aufgabe ist keine Modelltest-Aufgabe.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$nowTs = time();
if (!assignment_is_active_now($assignment, $nowTs)) {
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
    http_response_code(409);
    echo json_encode(['error' => 'Die Aufgabe wurde noch nicht gestartet.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$deadlineTs = $deadlineAt !== '' ? strtotime($deadlineAt) : false;
if ($deadlineTs !== false && $nowTs > (int)$deadlineTs) {
    http_response_code(409);
    echo json_encode(['error' => 'Die Frist ist abgelaufen.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$correctTotal = $hoerenCorrect + $lesenCorrect;
$questionTotal = $hoerenTotal + $lesenTotal;
$dtzPercent = $questionTotal > 0 ? (int)round(($correctTotal / $questionTotal) * 100) : 0;
$writingPercent = $schreibenMax > 0 ? (int)round(($schreibenScore / $schreibenMax) * 100) : 0;
$overallPercent = $schreibenMax > 0
    ? (int)round(($dtzPercent * 0.7) + ($writingPercent * 0.3))
    : $dtzPercent;
$level = modelltest_level_from_percent_local($overallPercent);

$nowIso = gmdate('c', $nowTs);
try {
    $resultSuffix = bin2hex(random_bytes(4));
} catch (Throwable $e) {
    $resultSuffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
}
$resultId = 'mtres-' . gmdate('YmdHis', $nowTs) . '-' . $resultSuffix;

$state['last_modelltest_result'] = [
    'result_id' => $resultId,
    'saved_at' => $nowIso,
    'hoeren_correct' => $hoerenCorrect,
    'hoeren_total' => $hoerenTotal,
    'lesen_correct' => $lesenCorrect,
    'lesen_total' => $lesenTotal,
    'schreiben_score' => $schreibenScore,
    'schreiben_max' => $schreibenMax,
    'overall_percent' => $overallPercent,
    'level' => $level,
    'dtz_percent' => $dtzPercent,
    'writing_percent' => $writingPercent,
];
$state['submitted_at'] = $nowIso;
$state['last_upload_id'] = $resultId;
$state['submission_count'] = max(1, (int)($state['submission_count'] ?? 0) + 1);

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
    'level' => $level,
]);

echo json_encode([
    'ok' => true,
    'saved_at' => $nowIso,
    'result_id' => $resultId,
    'overall_percent' => $overallPercent,
    'level' => $level,
], JSON_UNESCAPED_UNICODE);
