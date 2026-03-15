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
require_hauptadmin_session_json();

function mod6_storage_file(): string
{
    return __DIR__ . '/storage/mod6_submissions.json';
}

$records = [];
$file = mod6_storage_file();
if (is_file($file)) {
    $raw = file_get_contents($file);
    $tmp = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($tmp)) {
        foreach ($tmp as $row) {
            if (!is_array($row)) {
                continue;
            }
            $records[] = [
                'id' => (string)($row['id'] ?? ''),
                'submitted_at' => (string)($row['submitted_at'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'student_username' => (string)($row['student_username'] ?? ''),
                'student_display_name' => (string)($row['student_display_name'] ?? ''),
                'teacher_username' => (string)($row['teacher_username'] ?? ''),
                'answers' => is_array($row['answers'] ?? null) ? $row['answers'] : [],
            ];
        }
    }
}

usort($records, static function (array $a, array $b): int {
    return strcmp((string)($b['submitted_at'] ?? ''), (string)($a['submitted_at'] ?? ''));
});

$limitRaw = (string)($_GET['limit'] ?? '200');
$limit = (int)$limitRaw;
if ($limit < 1) $limit = 200;
if ($limit > 1000) $limit = 1000;

echo json_encode([
    'records' => array_slice($records, 0, $limit),
], JSON_UNESCAPED_UNICODE);

