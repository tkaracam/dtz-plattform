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
require_admin_session_json();

$courses = load_courses();
$users = load_student_users();

$students = [];
foreach ($users as $u) {
    if (!is_array($u)) continue;
    $students[] = [
        'username' => (string)($u['username'] ?? ''),
        'display_name' => (string)($u['display_name'] ?? ''),
        'email' => (string)($u['email'] ?? ''),
        'phone' => (string)($u['phone'] ?? ''),
        'active' => (bool)($u['active'] ?? false),
    ];
}

echo json_encode([
    'courses' => array_values(array_filter($courses, static fn($c) => is_array($c))),
    'students' => $students,
], JSON_UNESCAPED_UNICODE);
