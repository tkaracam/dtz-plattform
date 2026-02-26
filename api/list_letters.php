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

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = 100;
if (isset($body['limit']) && is_numeric($body['limit'])) {
    $limit = (int)$body['limit'];
}
$limit = max(1, min(200, $limit));

$dateFrom = trim((string)($body['date_from'] ?? ''));
$dateTo = trim((string)($body['date_to'] ?? ''));
$studentQuery = mb_strtolower(trim((string)($body['student_query'] ?? '')));
$textQuery = mb_strtolower(trim((string)($body['text_query'] ?? '')));

$tsFrom = null;
$tsTo = null;
if ($dateFrom !== '') {
    $tmp = strtotime($dateFrom . ' 00:00:00');
    if ($tmp !== false) {
        $tsFrom = $tmp;
    }
}
if ($dateTo !== '') {
    $tmp = strtotime($dateTo . ' 23:59:59');
    if ($tmp !== false) {
        $tsTo = $tmp;
    }
}

$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir)) {
    echo json_encode(['records' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$files = glob($storageDir . '/letters-*.jsonl') ?: [];
rsort($files, SORT_STRING);

$records = [];

foreach ($files as $file) {
    $handle = @fopen($file, 'rb');
    if (!$handle) {
        continue;
    }

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $record = json_decode($line, true);
        if (!is_array($record)) {
            continue;
        }

        $createdAt = (string)($record['created_at'] ?? '');
        $ts = strtotime($createdAt);
        if ($ts === false) {
            $ts = 0;
        }

        if ($tsFrom !== null && $ts < $tsFrom) {
            continue;
        }
        if ($tsTo !== null && $ts > $tsTo) {
            continue;
        }

        $studentName = (string)($record['student_name'] ?? '');
        $taskPrompt = (string)($record['task_prompt'] ?? '');
        $letterText = (string)($record['letter_text'] ?? '');

        if ($studentQuery !== '' && !str_contains(mb_strtolower($studentName), $studentQuery)) {
            continue;
        }

        if ($textQuery !== '') {
            $haystack = mb_strtolower($taskPrompt . "\n" . $letterText);
            if (!str_contains($haystack, $textQuery)) {
                continue;
            }
        }

        $records[] = [
            'upload_id' => (string)($record['upload_id'] ?? ''),
            'created_at' => $createdAt,
            'student_name' => $studentName,
            'student_username' => (string)($record['student_username'] ?? ''),
            'task_prompt' => $taskPrompt,
            'required_points' => is_array($record['required_points'] ?? null) ? $record['required_points'] : [],
            'letter_text' => $letterText,
        ];
    }

    fclose($handle);
}

usort($records, static function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

if (count($records) > $limit) {
    $records = array_slice($records, 0, $limit);
}

echo json_encode(['records' => $records], JSON_UNESCAPED_UNICODE);
