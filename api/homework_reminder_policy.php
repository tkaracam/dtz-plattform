<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/homework_lib.php';

$admin = require_admin_role_json(['hauptadmin', 'docent']);
$teacherUsername = auth_lower_text((string)($admin['username'] ?? ''));
if ($teacherUsername === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Lehrkraft-Benutzername fehlt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $profile = load_homework_reminder_policy_for_teacher($teacherUsername);
    echo json_encode([
        'ok' => true,
        'teacher_username' => $teacherUsername,
        'policy' => $profile,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur GET/POST wird unterstützt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($body['action'] ?? 'save'));
if ($action !== 'save') {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Aktion.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$policy = is_array($body['policy'] ?? null) ? $body['policy'] : null;
if (!is_array($policy)) {
    http_response_code(400);
    echo json_encode(['error' => 'policy ist erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!save_homework_reminder_policy_for_teacher($teacherUsername, $policy)) {
    http_response_code(500);
    echo json_encode(['error' => 'Policy konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('homework_reminder_policy_save', [
    'teacher_username' => $teacherUsername,
]);

echo json_encode([
    'ok' => true,
    'teacher_username' => $teacherUsername,
    'policy' => load_homework_reminder_policy_for_teacher($teacherUsername),
], JSON_UNESCAPED_UNICODE);

