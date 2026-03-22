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
    echo json_encode(['error' => 'Nur GET wird unterstützt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/letter_reviews.php';
$member = require_member_session_json();
$username = mb_strtolower(trim((string)($member['username'] ?? '')));

$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir)) {
    echo json_encode([
        'corrections' => [],
        'latest' => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function member_portal_read_jsonl_records(string $pattern): array
{
    $files = glob($pattern) ?: [];
    rsort($files, SORT_STRING);
    $out = [];
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
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        fclose($handle);
    }
    return $out;
}

$reviewsByUpload = load_letter_reviews_index($storageDir);
$approvedLetterCorrections = [];
$latestLetterCorrection = null;
$latestTs = 0;

foreach (member_portal_read_jsonl_records($storageDir . '/letters-*.jsonl') as $row) {
    $u = mb_strtolower(trim((string)($row['student_username'] ?? '')));
    if ($u !== $username) {
        continue;
    }
    $uploadId = trim((string)($row['upload_id'] ?? ''));
    $review = is_array($reviewsByUpload[$uploadId] ?? null) ? $reviewsByUpload[$uploadId] : null;
    $decision = strtolower((string)($review['decision'] ?? ''));
    if ($decision !== 'approve') {
        continue;
    }
    $result = is_array($review['correction_result'] ?? null) ? $review['correction_result'] : null;
    if (!$result) {
        continue;
    }
    $recordWithMeta = array_merge($result, [
        'created_at' => (string)($review['reviewed_at'] ?? $row['created_at'] ?? ''),
        'topic' => trim((string)($row['task_prompt'] ?? '')),
        'upload_id' => $uploadId,
    ]);
    $approvedLetterCorrections[] = $recordWithMeta;
    $reviewTs = strtotime((string)($review['reviewed_at'] ?? '')) ?: 0;
    if ($reviewTs >= $latestTs) {
        $latestLetterCorrection = $recordWithMeta;
        $latestTs = $reviewTs;
    }
}

usort($approvedLetterCorrections, static function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

echo json_encode([
    'corrections' => $approvedLetterCorrections,
    'latest' => $latestLetterCorrection,
], JSON_UNESCAPED_UNICODE);
