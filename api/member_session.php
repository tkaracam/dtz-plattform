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
    echo json_encode(['error' => 'Nur GET wird unterstützt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/auth.php';
start_secure_session();
if (!empty($_SESSION['member_authenticated'])) {
    enforce_session_timeout_json();
}

$authenticated = !empty($_SESSION['member_authenticated']) && !empty($_SESSION['member_username']);

echo json_encode([
    'authenticated' => $authenticated,
    'role' => $authenticated ? 'member' : '',
    'role_key' => $authenticated ? 'member' : '',
    'username' => $authenticated ? (string)($_SESSION['member_username'] ?? '') : '',
    'display_name' => $authenticated ? (string)($_SESSION['member_display_name'] ?? '') : '',
    'email' => $authenticated ? (string)($_SESSION['member_email'] ?? '') : '',
], JSON_UNESCAPED_UNICODE);
