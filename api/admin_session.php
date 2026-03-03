<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

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

start_secure_session();
if (!empty($_SESSION['admin_authenticated'])) {
    enforce_session_timeout_json();
}

$role = (string)($_SESSION['admin_role'] ?? '');
if ($role !== 'owner' && $role !== 'docent') {
    $role = '';
}
$username = mb_strtolower(trim((string)($_SESSION['admin_username'] ?? '')));
$displayName = trim((string)($_SESSION['admin_display_name'] ?? ''));
$bamfCode = normalize_bamf_code((string)($_SESSION['admin_bamf_code'] ?? ''));
$authenticated = !empty($_SESSION['admin_authenticated']);
if ($authenticated && $role === '') {
    $role = 'owner';
}
if ($authenticated && $role === 'owner' && $username === '') {
    $username = 'admin';
}

echo json_encode([
    'authenticated' => $authenticated,
    'role' => $authenticated ? $role : '',
    'username' => $authenticated ? $username : '',
    'display_name' => $authenticated ? $displayName : '',
    'bamf_code' => $authenticated ? $bamfCode : '',
    'permissions' => [
        'manage_teachers' => $authenticated && $role === 'owner',
    ],
], JSON_UNESCAPED_UNICODE);
