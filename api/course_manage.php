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
    echo json_encode(['error' => 'Nur POST wird unterstuetzt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/auth.php';
require_admin_session_json();

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($body['action'] ?? ''));
$courses = load_courses();

if ($action === 'create') {
    $name = trim((string)($body['name'] ?? ''));
    $level = trim((string)($body['level'] ?? ''));
    $schedule = trim((string)($body['schedule'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Kursname ist erforderlich.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $suffix = bin2hex(random_bytes(3));
    } catch (Throwable $e) {
        $suffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 6);
    }
    $id = 'kurs-' . $suffix;
    $courses[] = [
        'course_id' => $id,
        'name' => $name,
        'level' => $level,
        'schedule' => $schedule,
        'active' => true,
        'members' => [],
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];
} elseif ($action === 'set_members') {
    $courseId = trim((string)($body['course_id'] ?? ''));
    $members = $body['members'] ?? [];
    if ($courseId === '' || !is_array($members)) {
        http_response_code(400);
        echo json_encode(['error' => 'course_id und members sind erforderlich.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $members = array_values(array_unique(array_filter(array_map(
        static fn($v) => mb_strtolower(trim((string)$v)),
        $members
    ), static fn($v) => (bool)preg_match('/^[a-z0-9._-]{3,32}$/', $v))));
    $found = false;
    foreach ($courses as &$c) {
        if (!is_array($c)) continue;
        if ((string)($c['course_id'] ?? '') !== $courseId) continue;
        $c['members'] = $members;
        $c['updated_at'] = gmdate('c');
        $found = true;
        break;
    }
    unset($c);
    if (!$found) {
        http_response_code(404);
        echo json_encode(['error' => 'Kurs nicht gefunden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} elseif ($action === 'set_active') {
    $courseId = trim((string)($body['course_id'] ?? ''));
    $active = !empty($body['active']);
    $found = false;
    foreach ($courses as &$c) {
        if (!is_array($c)) continue;
        if ((string)($c['course_id'] ?? '') !== $courseId) continue;
        $c['active'] = $active;
        $c['updated_at'] = gmdate('c');
        $found = true;
        break;
    }
    unset($c);
    if (!$found) {
        http_response_code(404);
        echo json_encode(['error' => 'Kurs nicht gefunden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltige Aktion.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!write_courses($courses)) {
    http_response_code(500);
    echo json_encode(['error' => 'Kursdaten konnten nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('course_manage', [
    'action' => $action,
    'course_id' => isset($courseId) ? (string)$courseId : (isset($id) ? (string)$id : ''),
]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
