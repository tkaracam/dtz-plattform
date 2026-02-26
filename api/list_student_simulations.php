<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur GET wird unterstuetzt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/auth.php';
$student = require_student_session_json();
$username = mb_strtolower(trim((string)($student['username'] ?? '')));

$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 10;
$limit = max(1, min(50, $limit));

$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir)) {
    echo json_encode(['records' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$files = glob($storageDir . '/simulations-*.jsonl') ?: [];
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
        $item = json_decode($line, true);
        if (!is_array($item)) {
            continue;
        }
        $u = mb_strtolower(trim((string)($item['student_username'] ?? '')));
        if ($u !== $username) {
            continue;
        }

        $records[] = [
            'record_id' => (string)($item['record_id'] ?? ''),
            'created_at' => (string)($item['created_at'] ?? ''),
            'duration_minutes' => (int)($item['duration_minutes'] ?? 0),
            'score_total' => (int)($item['score_total'] ?? 0),
            'niveau' => (string)($item['niveau'] ?? ''),
            'word_count' => (int)($item['word_count'] ?? 0),
            'auto_locked' => (bool)($item['auto_locked'] ?? false),
            'missing_topics' => is_array($item['missing_topics'] ?? null) ? $item['missing_topics'] : [],
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
