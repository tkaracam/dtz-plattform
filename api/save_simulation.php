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
$student = require_student_session_json();

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$durationMinutes = max(1, min(240, (int)($body['duration_minutes'] ?? 45)));
$scoreTotal = max(0, min(20, (int)($body['score_total'] ?? 0)));
$niveau = trim((string)($body['niveau'] ?? 'A2'));
$wordCount = max(0, min(3000, (int)($body['word_count'] ?? 0)));
$startedAt = trim((string)($body['started_at'] ?? ''));
$endedAt = trim((string)($body['ended_at'] ?? ''));
$autoLocked = (bool)($body['auto_locked'] ?? false);
$taskPrompt = trim((string)($body['task_prompt'] ?? ''));
$letterText = trim((string)($body['letter_text'] ?? ''));
$rubric = is_array($body['rubric'] ?? null) ? $body['rubric'] : [];
$missingTopics = is_array($body['missing_topics'] ?? null) ? $body['missing_topics'] : [];

if ($letterText === '') {
    http_response_code(400);
    echo json_encode(['error' => 'letter_text darf nicht leer sein.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$missingTopics = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $missingTopics), static fn($v) => $v !== ''));

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
$filePath = $storageDir . '/simulations-' . gmdate('Y-m-d') . '.jsonl';

$record = [
    'record_id' => $recordId,
    'created_at' => $createdAt,
    'student_username' => (string)$student['username'],
    'student_name' => $student['display_name'] !== '' ? (string)$student['display_name'] : (string)$student['username'],
    'duration_minutes' => $durationMinutes,
    'score_total' => $scoreTotal,
    'niveau' => $niveau,
    'word_count' => $wordCount,
    'started_at' => $startedAt,
    'ended_at' => $endedAt,
    'auto_locked' => $autoLocked,
    'task_prompt' => $taskPrompt,
    'letter_text' => $letterText,
    'rubric' => [
        'aufgabenbezug' => max(0, min(5, (int)($rubric['aufgabenbezug'] ?? 0))),
        'textaufbau' => max(0, min(5, (int)($rubric['textaufbau'] ?? 0))),
        'grammatik' => max(0, min(5, (int)($rubric['grammatik'] ?? 0))),
        'wortschatz_orthografie' => max(0, min(5, (int)($rubric['wortschatz_orthografie'] ?? 0))),
    ],
    'missing_topics' => $missingTopics,
    'meta' => [
        'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ],
];

$line = json_encode($record, JSON_UNESCAPED_UNICODE) . PHP_EOL;
if (@file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'In die Simulationsdatei konnte nicht geschrieben werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('simulation_save', [
    'record_id' => $recordId,
    'username' => (string)$student['username'],
    'score_total' => $scoreTotal,
    'duration_minutes' => $durationMinutes,
]);

echo json_encode([
    'ok' => true,
    'record_id' => $recordId,
    'created_at' => $createdAt,
], JSON_UNESCAPED_UNICODE);
