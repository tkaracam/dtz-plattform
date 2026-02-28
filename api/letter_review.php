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
    echo json_encode(['error' => 'Nur POST wird unterstuetzt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/auth.php';
require_admin_session_json();
require_once __DIR__ . '/letter_reviews.php';
require_once __DIR__ . '/correction_engine.php';

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadId = trim((string)($body['upload_id'] ?? ''));
$decision = mb_strtolower(trim((string)($body['decision'] ?? 'approve')));
$note = trim((string)($body['note'] ?? ''));

if ($uploadId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'upload_id ist erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($decision, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'decision muss approve oder reject sein.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$storageDir = __DIR__ . '/storage';
$letter = find_letter_record_by_upload_id($storageDir, $uploadId);
if (!is_array($letter)) {
    http_response_code(404);
    echo json_encode(['error' => 'Briefeintrag wurde nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$reviews = load_letter_reviews_index($storageDir);
$existing = is_array($reviews[$uploadId] ?? null) ? $reviews[$uploadId] : null;
if ($existing && strtolower((string)($existing['decision'] ?? '')) === 'approve' && $decision === 'approve') {
    echo json_encode([
        'ok' => true,
        'already_approved' => true,
        'message' => 'Dieser Eintrag wurde bereits freigegeben.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$now = gmdate('c');
$reviewRecord = [
    'upload_id' => $uploadId,
    'student_username' => (string)($letter['student_username'] ?? ''),
    'student_name' => (string)($letter['student_name'] ?? ''),
    'decision' => $decision,
    'note' => $note,
    'reviewed_at' => $now,
    'reviewed_by' => 'admin',
    'correction_result' => null,
];

if ($decision === 'approve') {
    $letterText = trim((string)($letter['letter_text'] ?? ''));
    $taskPrompt = trim((string)($letter['task_prompt'] ?? ''));
    $requiredPoints = is_array($letter['required_points'] ?? null) ? $letter['required_points'] : [];
    if ($letterText === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Der Brieftext ist leer und kann nicht korrigiert werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $reviewRecord['correction_result'] = dtz_run_correction($letterText, $taskPrompt, $requiredPoints);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => dtz_sanitize_external_error($e->getMessage())], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!append_letter_review($storageDir, $reviewRecord)) {
    http_response_code(500);
    echo json_encode(['error' => 'Die Freigabe konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('letter_review_' . $decision, [
    'upload_id' => $uploadId,
    'student_username' => (string)($letter['student_username'] ?? ''),
]);

echo json_encode([
    'ok' => true,
    'upload_id' => $uploadId,
    'decision' => $decision,
    'reviewed_at' => $now,
], JSON_UNESCAPED_UNICODE);
