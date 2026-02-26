<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur GET wird unterstuetzt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/auth.php';
require_admin_session_json();

$file = __DIR__ . '/storage/virtual_rooms.json';
$rooms = [];
if (is_file($file)) {
    $raw = file_get_contents($file);
    $tmp = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($tmp)) $rooms = $tmp;
}

$now = time();
$changed = false;
foreach ($rooms as &$room) {
    $end = strtotime((string)($room['end_at'] ?? '')) ?: 0;
    if (($room['status'] ?? 'active') === 'active' && $end > 0 && $now >= $end) {
      $room['status'] = 'closed';
      $changed = true;
    }
}
unset($room);

if ($changed) {
    @file_put_contents($file, json_encode(array_values($rooms), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX);
}

usort($rooms, static function(array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

echo json_encode(['rooms' => array_slice($rooms, 0, 100)], JSON_UNESCAPED_UNICODE);
