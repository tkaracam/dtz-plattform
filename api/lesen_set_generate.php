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
    echo json_encode(['error' => 'Nur POST wird unterstützt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/training_set_lib.php';

require_admin_role_json(['hauptadmin', 'docent']);

$raw = file_get_contents('php://input') ?: '';
$body = [];
if (trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $body = $decoded;
}

$count = (int)($body['count'] ?? 40);
$teil = (int)($body['teil'] ?? 0);
$teil = $teil >= 1 && $teil <= 5 ? $teil : 0;
$includeExplanation = !empty($body['include_explanation']);

try {
    $set = create_training_set('lesen', $count, $includeExplanation, $teil);
    $downloadName = 'dtz_lid_lesen_set_' . gmdate('Ymd_His') . '.json';

    echo json_encode([
        'ok' => true,
        'download_name' => $downloadName,
        'set' => $set,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Lesen-Set konnte nicht erstellt werden.',
        'detail' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
