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

$courses = load_courses();
$users = load_student_users();

$students = [];
$isDocent = normalize_admin_role_key((string)($admin['role_key'] ?? '')) === 'docent';
$docentUsername = auth_lower_text((string)($admin['username'] ?? ''));
foreach ($users as $u) {
    if (!is_array($u)) continue;
    if (!admin_can_access_student_record($u, $admin)) continue;
    $username = (string)($u['username'] ?? '');
    $realDisplayName = (string)($u['display_name'] ?? '');
    $nickname = $isDocent ? get_docent_student_nickname($docentUsername, $username) : '';
    $effectiveDisplay = $nickname !== '' ? $nickname : $realDisplayName;
    $students[] = [
        'username' => $username,
        'display_name' => $effectiveDisplay,
        'real_display_name' => $realDisplayName,
        'nickname' => $nickname,
        'email' => (string)($u['email'] ?? ''),
        'phone' => (string)($u['phone'] ?? ''),
        'active' => (bool)($u['active'] ?? false),
    ];
}

echo json_encode([
    'courses' => array_values(array_filter($courses, function ($c) use ($admin): bool {
        return is_array($c) && admin_can_access_course_record($c, $admin);
    })),
    'students' => $students,
], JSON_UNESCAPED_UNICODE);
