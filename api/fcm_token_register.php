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

function fcm_tokens_file_path(): string
{
    return __DIR__ . '/storage/fcm_tokens.json';
}

function load_fcm_tokens(): array
{
    $file = fcm_tokens_file_path();
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function write_fcm_tokens(array $rows): bool
{
    $storage = __DIR__ . '/storage';
    if (!is_dir($storage) && !mkdir($storage, 0775, true) && !is_dir($storage)) {
        return false;
    }
    $json = json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return @file_put_contents(fcm_tokens_file_path(), $json . PHP_EOL, LOCK_EX) !== false;
}

start_secure_session();
if (!empty($_SESSION['admin_authenticated']) || !empty($_SESSION['student_authenticated'])) {
    enforce_session_timeout_json();
}

$username = '';
$role = '';
if (!empty($_SESSION['student_authenticated']) && !empty($_SESSION['student_username'])) {
    $username = mb_strtolower(trim((string)$_SESSION['student_username']));
    $role = 'student';
} elseif (!empty($_SESSION['admin_authenticated']) && !empty($_SESSION['admin_username'])) {
    $username = mb_strtolower(trim((string)$_SESSION['admin_username']));
    $role = 'admin';
}

if ($username === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht angemeldet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = trim((string)($body['token'] ?? ''));
$platform = trim((string)($body['platform'] ?? 'android'));
$appVersion = trim((string)($body['app_version'] ?? ''));

if ($token === '' || strlen($token) < 20) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiger FCM-Token.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = load_fcm_tokens();
$now = gmdate('c');
$found = false;

foreach ($rows as $i => $row) {
    if (!is_array($row)) {
        continue;
    }
    if ((string)($row['token'] ?? '') !== $token) {
        continue;
    }
    $rows[$i]['username'] = $username;
    $rows[$i]['role'] = $role;
    $rows[$i]['platform'] = $platform !== '' ? $platform : 'android';
    $rows[$i]['app_version'] = $appVersion;
    $rows[$i]['updated_at'] = $now;
    $rows[$i]['active'] = true;
    $found = true;
    break;
}

if (!$found) {
    $rows[] = [
        'token' => $token,
        'username' => $username,
        'role' => $role,
        'platform' => $platform !== '' ? $platform : 'android',
        'app_version' => $appVersion,
        'created_at' => $now,
        'updated_at' => $now,
        'active' => true,
    ];
}

if (!write_fcm_tokens($rows)) {
    http_response_code(500);
    echo json_encode(['error' => 'Token konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('fcm_token_register', [
    'username' => $username,
    'role' => $role,
    'platform' => $platform !== '' ? $platform : 'android',
]);

echo json_encode([
    'ok' => true,
    'username' => $username,
    'role' => $role,
], JSON_UNESCAPED_UNICODE);

