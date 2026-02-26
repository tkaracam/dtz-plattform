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
start_secure_session();
if (!empty($_SESSION['student_authenticated'])) {
    enforce_session_timeout_json();
}

echo json_encode([
    'authenticated' => !empty($_SESSION['student_authenticated']) && !empty($_SESSION['student_username']),
    'username' => (string)($_SESSION['student_username'] ?? ''),
    'display_name' => (string)($_SESSION['student_display_name'] ?? ''),
], JSON_UNESCAPED_UNICODE);
