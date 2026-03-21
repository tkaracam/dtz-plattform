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
$admin = require_admin_role_json(['hauptadmin', 'docent']);

$users = load_student_users();
$out = [];
$isDocent = normalize_admin_role_key((string)($admin['role_key'] ?? '')) === 'docent';
$docentUsername = auth_lower_text((string)($admin['username'] ?? ''));
foreach ($users as $user) {
    if (!is_array($user)) continue;
    if (!admin_can_access_student_record($user, $admin)) continue;
    $username = (string)($user['username'] ?? '');
    $realDisplayName = (string)($user['display_name'] ?? '');
    $nickname = $isDocent ? get_docent_student_nickname($docentUsername, $username) : '';
    $effectiveDisplay = $nickname !== '' ? $nickname : $realDisplayName;
    $out[] = [
        'username' => $username,
        'display_name' => $effectiveDisplay,
        'real_display_name' => $realDisplayName,
        'nickname' => $nickname,
        'email' => (string)($user['email'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'teacher_username' => (string)($user['teacher_username'] ?? ''),
        'active' => (bool)($user['active'] ?? false),
        'created_at' => (string)($user['created_at'] ?? ''),
    ];
}

echo json_encode(['users' => $out], JSON_UNESCAPED_UNICODE);
