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
$studentSession = require_student_session_json();

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$studentName = trim((string)($body['student_name'] ?? ''));
$taskPrompt = trim((string)($body['task_prompt'] ?? ''));
$letterText = trim((string)($body['letter_text'] ?? ''));
$requiredPoints = $body['required_points'] ?? [];

if ($letterText === '') {
    http_response_code(400);
    echo json_encode(['error' => 'letter_text darf nicht leer sein.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($letterText) > 12000) {
    http_response_code(413);
    echo json_encode(['error' => 'Der Brieftext ist zu lang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($requiredPoints)) {
    $requiredPoints = [];
}

$requiredPoints = array_values(array_filter(array_map(
    static fn($item) => trim((string)$item),
    $requiredPoints
), static fn($item) => $item !== ''));

$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Das Storage-Verzeichnis konnte nicht erstellt werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$suffix = '';
try {
    $suffix = bin2hex(random_bytes(4));
} catch (Throwable $e) {
    $suffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
}
$uploadId = gmdate('YmdHis') . '-' . $suffix;
$createdAt = gmdate('c');
$filePath = $storageDir . '/letters-' . gmdate('Y-m-d') . '.jsonl';

$record = [
    'upload_id' => $uploadId,
    'created_at' => $createdAt,
    'student_name' => $studentName !== '' ? $studentName : ($studentSession['display_name'] !== '' ? $studentSession['display_name'] : $studentSession['username']),
    'student_username' => $studentSession['username'],
    'task_prompt' => $taskPrompt,
    'required_points' => $requiredPoints,
    'letter_text' => $letterText,
    'meta' => [
        'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ],
];

$line = json_encode($record, JSON_UNESCAPED_UNICODE) . PHP_EOL;
$bytes = @file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);
if ($bytes === false) {
    http_response_code(500);
    echo json_encode(['error' => 'In die Aufzeichnungsdatei konnte nicht geschrieben werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'upload_id' => $uploadId,
    'created_at' => $createdAt,
    'file' => basename($filePath),
], JSON_UNESCAPED_UNICODE);
