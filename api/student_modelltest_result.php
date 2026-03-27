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
    api_error(401, 'unauthorized', 'Nicht autorisiert.');
}

$body = require_json_body_or_400(65536);

$assignmentId = trim((string)($body['assignment_id'] ?? ''));
if ($assignmentId === '') {
    api_error(400, 'assignment_id_missing', 'assignment_id ist erforderlich.');
}

$hoerenCorrect = payload_required_non_negative_int($body, 'hoeren_correct');
$hoerenTotal = payload_required_non_negative_int($body, 'hoeren_total');
$lesenCorrect = payload_required_non_negative_int($body, 'lesen_correct');
$lesenTotal = payload_required_non_negative_int($body, 'lesen_total');
$schreibenScore = payload_required_non_negative_int($body, 'schreiben_score');
$schreibenMax = payload_required_non_negative_int($body, 'schreiben_max');
if ($hoerenCorrect === null || $hoerenTotal === null || $lesenCorrect === null || $lesenTotal === null || $schreibenScore === null || $schreibenMax === null) {
    api_error(400, 'invalid_modelltest_payload', 'Ungültige Modelltest-Werte: alle Ergebnisfelder müssen nicht-negative Ganzzahlen sein.');
}
if ($hoerenTotal > 200 || $lesenTotal > 200 || $schreibenMax > 100) {
    api_error(400, 'invalid_modelltest_bounds', 'Ungültige Modelltest-Werte: totals/max außerhalb des erlaubten Bereichs.');
}
if ($hoerenTotal <= 0 || $lesenTotal <= 0) {
    api_error(400, 'invalid_modelltest_totals', 'Ungültige Modelltest-Teile: Hören und Lesen müssen > 0 sein.');
}
if ($hoerenCorrect > $hoerenTotal || $lesenCorrect > $lesenTotal) {
    api_error(400, 'invalid_modelltest_correctness', 'Ungültige Modelltest-Werte: richtig > gesamt.');
}
if ($schreibenMax > 0 && $schreibenScore > $schreibenMax) {
    api_error(400, 'invalid_schreiben_score', 'Ungültige Schreibpunkte: score > max.');
}
if ($schreibenMax <= 0 && $schreibenScore > 0) {
    api_error(400, 'invalid_schreiben_max', 'Ungültige Schreibpunkte ohne Maximalwert.');
}

try {
    $resultSuffix = bin2hex(random_bytes(4));
} catch (Throwable $e) {
    $resultSuffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
}

$txn = [
    'http' => 200,
    'err' => '',
    'now_ts' => time(),
    'overall_percent' => 0,
    'level' => '',
    'result_id' => '',
    'now_iso' => '',
];

$mutateOk = homework_assignments_mutate(function (array $items) use (
    $assignmentId,
    $username,
    $hoerenCorrect,
    $hoerenTotal,
    $lesenCorrect,
    $lesenTotal,
    $schreibenScore,
    $schreibenMax,
    &$txn,
    $resultSuffix
): array|false {
    $nowTs = time();
    $txn['now_ts'] = $nowTs;
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

    $templateId = mb_strtolower(trim((string)($assignment['template_id'] ?? '')));
    if ($templateId !== 'dtz-mock-pruefung-komplett') {
        $txn['http'] = 400;
        $txn['err'] = 'Diese Aufgabe ist keine Modelltest-Aufgabe.';
        return false;
    }

    if (!assignment_is_active_now($assignment, $nowTs)) {
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
        $txn['http'] = 409;
        $txn['err'] = 'Die Aufgabe wurde noch nicht gestartet.';
        return false;
    }
    $deadlineTs = $deadlineAt !== '' ? strtotime($deadlineAt) : false;
    if ($deadlineTs !== false && $nowTs > (int)$deadlineTs) {
        $txn['http'] = 409;
        $txn['err'] = 'Die Frist ist abgelaufen.';
        return false;
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
    $resultId = 'mtres-' . gmdate('YmdHis', $nowTs) . '-' . $resultSuffix;

    $txn['overall_percent'] = $overallPercent;
    $txn['level'] = $level;
    $txn['result_id'] = $resultId;
    $txn['now_iso'] = $nowIso;

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

    return $items;
});

if (!$mutateOk) {
    if ($txn['http'] !== 200) {
        api_error((int)$txn['http'], 'modelltest_result_rejected', (string)$txn['err']);
    }
    api_error(500, 'modelltest_result_store_failed', 'Modelltest-Ergebnis konnte nicht gespeichert werden.');
}

$overallPercent = (int)$txn['overall_percent'];
$level = (string)$txn['level'];
$resultId = (string)$txn['result_id'];
$nowIso = (string)$txn['now_iso'];

append_audit_log('student_modelltest_result_saved', [
    'assignment_id' => $assignmentId,
    'student_username' => $username,
    'overall_percent' => $overallPercent,
    'level' => $level,
]);

api_ok('modelltest_result_saved', 'Modelltest-Ergebnis gespeichert.', [
    'saved_at' => $nowIso,
    'result_id' => $resultId,
    'overall_percent' => $overallPercent,
    'level' => $level,
]);
