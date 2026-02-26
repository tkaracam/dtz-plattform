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

$files = glob($storageDir . '/bsk-*.jsonl') ?: [];
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
        $studentUsername = (string)($record['student_username'] ?? '');
        $recommendation = (string)($record['recommendation'] ?? '');

        if ($studentQuery !== '') {
            $studentHay = mb_strtolower($studentName . ' ' . $studentUsername);
            if (!str_contains($studentHay, $studentQuery)) {
                continue;
            }
        }

        if ($textQuery !== '') {
            $haystack = mb_strtolower(
                $recommendation . "\n" .
                (string)($record['goal'] ?? '') . "\n" .
                (string)($record['level'] ?? '') . "\n" .
                (string)($record['field'] ?? '') . "\n" .
                (string)($record['difficulty'] ?? '') . "\n" .
                (string)($record['source'] ?? '')
            );
            if (!str_contains($haystack, $textQuery)) {
                continue;
            }
        }

        $records[] = [
            'record_id' => (string)($record['record_id'] ?? ''),
            'created_at' => $createdAt,
            'student_name' => $studentName,
            'student_username' => $studentUsername,
            'goal' => (string)($record['goal'] ?? ''),
            'level' => (string)($record['level'] ?? ''),
            'field' => (string)($record['field'] ?? ''),
            'difficulty' => (string)($record['difficulty'] ?? ''),
            'source' => (string)($record['source'] ?? ''),
            'recommendation' => $recommendation,
            'score_total' => (int)($record['score_total'] ?? 0),
            'score_correct' => (int)($record['score_correct'] ?? 0),
            'score_wrong' => (int)($record['score_wrong'] ?? 0),
            'score_open' => (int)($record['score_open'] ?? 0),
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
