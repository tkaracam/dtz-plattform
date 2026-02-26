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

$goal = trim((string)($body['goal'] ?? ''));
$level = trim((string)($body['level'] ?? ''));
$field = trim((string)($body['field'] ?? ''));
$difficulty = trim((string)($body['difficulty'] ?? ''));
$source = trim((string)($body['source'] ?? 'generated_internal'));
$recommendation = trim((string)($body['recommendation'] ?? ''));

$scoreTotal = max(0, (int)($body['score_total'] ?? 0));
$scoreCorrect = max(0, (int)($body['score_correct'] ?? 0));
$scoreWrong = max(0, (int)($body['score_wrong'] ?? 0));
$scoreOpen = max(0, (int)($body['score_open'] ?? 0));
$answers = $body['answers'] ?? [];

if (!is_array($answers)) {
    $answers = [];
}

if ($source === '') {
    $source = 'generated_internal';
}

$sanitizedAnswers = [];
foreach ($answers as $row) {
    if (!is_array($row)) {
        continue;
    }
    $sanitizedAnswers[] = [
        'question' => trim((string)($row['question'] ?? '')),
        'selected' => trim((string)($row['selected'] ?? '')),
        'correct' => trim((string)($row['correct'] ?? '')),
        'is_correct' => (bool)($row['is_correct'] ?? false),
    ];
}

$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Das Storage-Verzeichnis konnte nicht erstellt werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $suffix = bin2hex(random_bytes(4));
} catch (Throwable $e) {
    $suffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
}

$recordId = gmdate('YmdHis') . '-' . $suffix;
$createdAt = gmdate('c');
$filePath = $storageDir . '/bsk-' . gmdate('Y-m-d') . '.jsonl';

$record = [
    'record_id' => $recordId,
    'created_at' => $createdAt,
    'student_name' => $studentSession['display_name'] !== '' ? $studentSession['display_name'] : $studentSession['username'],
    'student_username' => $studentSession['username'],
    'goal' => $goal,
    'level' => $level,
    'field' => $field,
    'difficulty' => $difficulty,
    'source' => $source,
    'recommendation' => $recommendation,
    'score_total' => $scoreTotal,
    'score_correct' => $scoreCorrect,
    'score_wrong' => $scoreWrong,
    'score_open' => $scoreOpen,
    'answers' => $sanitizedAnswers,
    'meta' => [
        'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ],
];

$line = json_encode($record, JSON_UNESCAPED_UNICODE) . PHP_EOL;
$bytes = @file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);
if ($bytes === false) {
    http_response_code(500);
    echo json_encode(['error' => 'In die BSK-Datei konnte nicht geschrieben werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('bsk_save', [
    'record_id' => $recordId,
    'username' => (string)$studentSession['username'],
    'level' => $level,
    'field' => $field,
    'score_correct' => $scoreCorrect,
    'score_total' => $scoreTotal,
]);

echo json_encode([
    'ok' => true,
    'record_id' => $recordId,
    'created_at' => $createdAt,
    'file' => basename($filePath),
], JSON_UNESCAPED_UNICODE);
