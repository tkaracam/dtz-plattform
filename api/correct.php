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
require_student_session_json();
require_once __DIR__ . '/correction_engine.php';

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$letterText = trim((string)($body['letter_text'] ?? ''));
$taskPrompt = trim((string)($body['task_prompt'] ?? ''));
$requiredPoints = $body['required_points'] ?? [];

if ($letterText === '') {
    http_response_code(400);
    echo json_encode(['error' => 'letter_text darf nicht leer sein.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($requiredPoints)) {
    $requiredPoints = [];
}

try {
    $result = dtz_run_correction($letterText, $taskPrompt, $requiredPoints);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => dtz_sanitize_external_error($e->getMessage())], JSON_UNESCAPED_UNICODE);
}
