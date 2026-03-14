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

require_student_session_json();

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

$module = normalize_training_module((string)($body['module'] ?? 'lesen'));
if ($module === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Modul muss "lesen" oder "hören" sein.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$teil = normalize_training_teil($module, (string)($body['teil'] ?? '0'));
if ($teil < 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Teil muss für Lesen 1-5 oder für Hören 1-4 sein (oder 0/alle).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$defaultCount = $module === 'lesen' ? 20 : 15;
$count = (int)($body['count'] ?? $defaultCount);
$includeExplanation = true;

try {
    $set = create_training_set($module, $count, $includeExplanation, $teil);
    echo json_encode([
        'ok' => true,
        'set' => $set,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'DTZ-Training konnte nicht erstellt werden.',
        'detail' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
