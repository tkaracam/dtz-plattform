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
$admin = require_admin_session_json();
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
if ($uploadId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'upload_id ist erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$storageDir = __DIR__ . '/storage';
$letter = find_letter_record_by_upload_id($storageDir, $uploadId);
if (!is_array($letter)) {
    http_response_code(404);
    echo json_encode(['error' => 'Briefeintrag wurde nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$letterStudent = mb_strtolower(trim((string)($letter['student_username'] ?? '')));
if (($admin['role'] ?? '') === 'docent' && !admin_can_access_student_username($letterStudent, $admin)) {
    http_response_code(403);
    echo json_encode(['error' => 'Keine Berechtigung fuer diesen Briefeintrag.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$letterText = trim((string)($letter['letter_text'] ?? ''));
$taskPrompt = trim((string)($letter['task_prompt'] ?? ''));
$requiredPoints = is_array($letter['required_points'] ?? null) ? $letter['required_points'] : [];

if ($letterText === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Der Brieftext ist leer und kann nicht korrigiert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $correction = dtz_run_correction($letterText, $taskPrompt, $requiredPoints);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => dtz_sanitize_external_error($e->getMessage())], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'upload_id' => $uploadId,
    'student_name' => (string)($letter['student_name'] ?? ''),
    'student_username' => (string)($letter['student_username'] ?? ''),
    'created_at' => (string)($letter['created_at'] ?? ''),
    'task_prompt' => $taskPrompt,
    'required_points' => $requiredPoints,
    'letter_text' => $letterText,
    'correction_result' => $correction,
], JSON_UNESCAPED_UNICODE);
