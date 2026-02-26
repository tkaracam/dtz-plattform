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

$limit = isset($body['limit']) && is_numeric($body['limit']) ? (int)$body['limit'] : 200;
$limit = max(1, min(1000, $limit));
$actionQuery = mb_strtolower(trim((string)($body['action_query'] ?? '')));
$dateFrom = trim((string)($body['date_from'] ?? ''));
$dateTo = trim((string)($body['date_to'] ?? ''));

$fromTs = $dateFrom !== '' ? strtotime($dateFrom . ' 00:00:00') : null;
$toTs = $dateTo !== '' ? strtotime($dateTo . ' 23:59:59') : null;

$file = __DIR__ . '/storage/audit_log.jsonl';
if (!is_file($file)) {
    echo json_encode(['records' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$records = [];
$handle = fopen($file, 'rb');
if ($handle) {
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $r = json_decode($line, true);
        if (!is_array($r)) continue;
        $created = (string)($r['created_at'] ?? '');
        $ts = $created !== '' ? strtotime($created) : false;
        if ($fromTs !== null && $ts !== false && $ts < $fromTs) continue;
        if ($toTs !== null && $ts !== false && $ts > $toTs) continue;
        if ($actionQuery !== '' && !str_contains(mb_strtolower((string)($r['action'] ?? '')), $actionQuery)) continue;
        $records[] = $r;
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
