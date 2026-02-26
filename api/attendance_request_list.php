<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
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
require_admin_session_json();

$file = __DIR__ . '/storage/attendance_requests.json';
$requests = [];
if (is_file($file)) {
    $raw = file_get_contents($file);
    $tmp = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($tmp)) $requests = $tmp;
}

$status = trim((string)($_GET['status'] ?? ''));
if ($status !== '') {
    $requests = array_values(array_filter($requests, static function ($r) use ($status) {
        return is_array($r) && (string)($r['status'] ?? '') === $status;
    }));
}

usort($requests, static function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

echo json_encode(['requests' => $requests], JSON_UNESCAPED_UNICODE);
