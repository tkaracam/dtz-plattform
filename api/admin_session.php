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
    echo json_encode(['error' => 'Nur GET wird unterstützt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

start_secure_session();
if (!empty($_SESSION['admin_authenticated'])) {
    enforce_session_timeout_json();
}

$sessionRole = (string)($_SESSION['admin_role'] ?? '');
$sessionRoleKey = (string)($_SESSION['admin_role_key'] ?? '');
$roleKey = normalize_admin_role_key($sessionRoleKey !== '' ? $sessionRoleKey : $sessionRole);
$role = $roleKey !== '' ? admin_role_key_to_legacy($roleKey) : '';
$username = mb_strtolower(trim((string)($_SESSION['admin_username'] ?? '')));
$displayName = trim((string)($_SESSION['admin_display_name'] ?? ''));
$authenticated = !empty($_SESSION['admin_authenticated']);
if ($authenticated && $role === '') {
    $roleKey = 'hauptadmin';
    $role = 'owner';
}
if ($authenticated && $roleKey === 'hauptadmin' && $username === '') {
    $username = 'admin';
}

echo json_encode([
    'authenticated' => $authenticated,
    'role' => $authenticated ? $role : '',
    'role_key' => $authenticated ? $roleKey : '',
    'username' => $authenticated ? $username : '',
    'display_name' => $authenticated ? $displayName : '',
    'permissions' => [
        'manage_teachers' => $authenticated && $roleKey === 'hauptadmin',
    ],
], JSON_UNESCAPED_UNICODE);
