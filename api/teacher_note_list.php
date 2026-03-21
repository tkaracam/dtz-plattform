<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
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

$courseId = trim((string)($_GET['course_id'] ?? ''));
$archivedOnly = ((string)($_GET['archived'] ?? '1')) !== '0';
$limit = (int)($_GET['limit'] ?? 100);
if ($limit < 1) $limit = 1;
if ($limit > 300) $limit = 300;

$courses = load_courses();
$courseById = [];
foreach ($courses as $course) {
    if (!is_array($course)) continue;
    $id = trim((string)($course['course_id'] ?? ''));
    if ($id === '') continue;
    $courseById[$id] = $course;
}

if ($courseId !== '') {
    $course = $courseById[$courseId] ?? null;
    if (!is_array($course)) {
        http_response_code(404);
        echo json_encode(['error' => 'Kurs nicht gefunden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!admin_can_access_course_record($course, $admin)) {
        http_response_code(403);
        echo json_encode(['error' => 'Keine Berechtigung für diesen Kurs.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$file = __DIR__ . '/storage/teacher_notes.json';
$rows = [];
if (is_file($file)) {
    $raw = file_get_contents($file);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $rows = is_array($decoded) ? $decoded : [];
}

$nowTs = time();
$out = [];
foreach ($rows as $row) {
    if (!is_array($row)) continue;

    $rowCourseId = trim((string)($row['course_id'] ?? ''));
    if ($courseId !== '' && $rowCourseId !== $courseId) continue;

    $target = auth_lower_text((string)($row['student_username'] ?? ''));
    if ($target !== '' && $target !== '*' && !admin_can_access_student_username($target, $admin)) {
        continue;
    }

    $expiresAt = trim((string)($row['expires_at'] ?? ''));
    if ($expiresAt === '') {
        $createdAt = trim((string)($row['created_at'] ?? ''));
        $createdTs = $createdAt !== '' ? strtotime($createdAt) : false;
        if ($createdTs !== false && (int)$createdTs > 0) {
            $expiresAt = gmdate('c', ((int)$createdTs) + (7 * 24 * 3600));
        }
    }

    $archived = false;
    if ($expiresAt !== '') {
        $expiresTs = strtotime($expiresAt);
        $archived = ($expiresTs !== false && (int)$expiresTs > 0 && $nowTs >= (int)$expiresTs);
    }

    if ($archivedOnly && !$archived) continue;

    $out[] = [
        'id' => (string)($row['id'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'expires_at' => $expiresAt,
        'archived' => $archived,
        'course_id' => $rowCourseId,
        'student_username' => $target,
        'note' => (string)($row['note'] ?? ''),
        'teacher' => (string)($row['teacher'] ?? 'Lehrkraft'),
    ];
}

usort($out, static function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

echo json_encode([
    'notes' => array_slice($out, 0, $limit),
    'archived_only' => $archivedOnly,
    'course_id' => $courseId,
], JSON_UNESCAPED_UNICODE);

