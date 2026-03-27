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
    require_once __DIR__ . '/auth.php';
    api_error(405, 'method_not_allowed', 'Nur POST wird unterstützt.');
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/training_set_lib.php';

require_student_session_json();

$raw = file_get_contents('php://input') ?: '';
$body = [];
if (trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        api_error(400, 'invalid_json', 'Ungültiges JSON wurde gesendet.');
    }
    $body = $decoded;
}

$module = normalize_training_module((string)($body['module'] ?? 'lesen'));
if ($module === '') {
    api_error(400, 'invalid_module', 'Modul muss "lesen" oder "hören" sein.');
}

$teil = normalize_training_teil($module, (string)($body['teil'] ?? '0'));
if ($teil < 0) {
    api_error(400, 'invalid_teil', 'Teil muss für Lesen 1-5 oder für Hören 1-4 sein (oder 0/alle).');
}

$defaultCount = $module === 'lesen' ? 20 : 15;
$count = (int)($body['count'] ?? $defaultCount);
$includeExplanation = true;
$poolName = normalize_training_pool((string)($body['pool'] ?? 'default'));

try {
    $set = create_training_set($module, $count, $includeExplanation, $teil, $poolName);
    api_ok('training_set_created', 'DTZ-Training erstellt.', [
        'set' => $set,
    ]);
} catch (Throwable $e) {
    api_error(500, 'training_set_create_failed', 'DTZ-Training konnte nicht erstellt werden.', [
        'detail' => $e->getMessage(),
    ]);
}
