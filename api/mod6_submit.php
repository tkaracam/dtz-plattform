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

check_rate_limit_json('mod6-submit', 60, 600);

function mod6_storage_file(): string
{
    return __DIR__ . '/storage/mod6_submissions.json';
}

function mod6_load_rows(): array
{
    $file = mod6_storage_file();
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function mod6_write_rows(array $rows): bool
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return file_put_contents(mod6_storage_file(), $json . PHP_EOL, LOCK_EX) !== false;
}

function mod6_clean_text(mixed $value, int $maxLen = 5000): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLen, 'UTF-8');
    }
    return substr($text, 0, $maxLen);
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    register_rate_limit_failure('mod6-submit');
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$name = mod6_clean_text($body['name'] ?? '', 120);
$answersRaw = $body['answers'] ?? [];
if ($name === '') {
    register_rate_limit_failure('mod6-submit');
    http_response_code(400);
    echo json_encode(['error' => 'Name ist erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!is_array($answersRaw)) {
    register_rate_limit_failure('mod6-submit');
    http_response_code(400);
    echo json_encode(['error' => 'Antwortformat ist ungültig.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedKeys = [
    'video_over_1',
    'video_over_2',
    'video_message',
    'v_question_1',
    'v_question_2',
    'v_question_3',
    'group_1',
    'group_2',
    'group_3',
    'group_4',
    'group_5',
    'konj_1',
    'konj_2',
    'konj_3',
    'produktion',
];

$answers = [];
$filledCount = 0;
foreach ($allowedKeys as $key) {
    $value = mod6_clean_text($answersRaw[$key] ?? '', 4000);
    $answers[$key] = $value;
    if ($value !== '') {
        $filledCount++;
    }
}

if ($filledCount === 0) {
    register_rate_limit_failure('mod6-submit');
    http_response_code(400);
    echo json_encode(['error' => 'Bitte mindestens eine Antwort eingeben.'], JSON_UNESCAPED_UNICODE);
    exit;
}

start_secure_session();
$studentUsername = '';
$studentDisplayName = '';
$teacherUsername = '';
if (!empty($_SESSION['student_authenticated']) && !empty($_SESSION['student_username'])) {
    $studentUsername = mod6_clean_text((string)($_SESSION['student_username'] ?? ''), 64);
    $studentDisplayName = mod6_clean_text((string)($_SESSION['student_display_name'] ?? ''), 120);
    $teacherUsername = mod6_clean_text((string)($_SESSION['student_teacher_username'] ?? ''), 64);
}

try {
    $suffix = bin2hex(random_bytes(4));
} catch (Throwable $e) {
    $suffix = substr(md5((string)microtime(true)), 0, 8);
}

$record = [
    'id' => 'mod6-' . gmdate('YmdHis') . '-' . $suffix,
    'submitted_at' => gmdate('c'),
    'name' => $name,
    'student_username' => $studentUsername,
    'student_display_name' => $studentDisplayName,
    'teacher_username' => $teacherUsername,
    'answers' => $answers,
];

$rows = mod6_load_rows();
$rows[] = $record;

if (!mod6_write_rows($rows)) {
    http_response_code(500);
    echo json_encode(['error' => 'Antworten konnten nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('mod6_submit', [
    'submission_id' => (string)$record['id'],
    'name' => $name,
    'student_username' => $studentUsername,
    'filled_answers' => $filledCount,
]);
clear_rate_limit_failures('mod6-submit');

echo json_encode([
    'ok' => true,
    'submission_id' => (string)$record['id'],
    'submitted_at' => (string)$record['submitted_at'],
], JSON_UNESCAPED_UNICODE);

