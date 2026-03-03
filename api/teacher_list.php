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
require_owner_session_json();

$teachers = load_teacher_users();
$out = [];
foreach ($teachers as $teacher) {
    if (!is_array($teacher)) {
        continue;
    }
    $out[] = [
        'username' => mb_strtolower(trim((string)($teacher['username'] ?? ''))),
        'display_name' => trim((string)($teacher['display_name'] ?? '')),
        'bamf_code' => normalize_bamf_code((string)($teacher['bamf_code'] ?? '')),
        'active' => (bool)($teacher['active'] ?? false),
        'created_at' => (string)($teacher['created_at'] ?? ''),
        'updated_at' => (string)($teacher['updated_at'] ?? ''),
    ];
}

usort($out, static function (array $a, array $b): int {
    return strcmp((string)($a['username'] ?? ''), (string)($b['username'] ?? ''));
});

echo json_encode(['teachers' => $out], JSON_UNESCAPED_UNICODE);
