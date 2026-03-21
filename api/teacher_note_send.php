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
$admin = require_admin_role_json(['hauptadmin', 'docent']);

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$targetType = trim((string)($body['target_type'] ?? 'course'));
$courseId = trim((string)($body['course_id'] ?? ''));
$noteText = trim((string)($body['note'] ?? ''));
$usernamesRaw = $body['usernames'] ?? [];
$timed = !empty($body['timed']);
$durationValue = (int)($body['duration_value'] ?? 0);
$durationUnit = trim((string)($body['duration_unit'] ?? ''));

if ($noteText === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Notiz ist erforderlich.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$noteText = mb_substr($noteText, 0, 1200);

$durationMinutes = 0;
$expiresAt = '';
if ($timed) {
    if ($durationValue <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Dauer.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $multiplier = 0;
    if ($durationUnit === 'minute') {
        $multiplier = 1;
    } elseif ($durationUnit === 'hour') {
        $multiplier = 60;
    } elseif ($durationUnit === 'day') {
        $multiplier = 1440;
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Zeit-Einheit.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $durationMinutes = $durationValue * $multiplier;
    if ($durationMinutes > 525600) {
        http_response_code(400);
        echo json_encode(['error' => 'Dauer ist zu lang (max. 365 Tage).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $expiresAt = gmdate('c', time() + ($durationMinutes * 60));
}

$allCourses = load_courses();
$course = null;
if ($courseId !== '') {
    foreach ($allCourses as $row) {
        if (!is_array($row)) continue;
        if ((string)($row['course_id'] ?? '') === $courseId) {
            $course = $row;
            break;
        }
    }
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

$activeStudents = [];
foreach (load_student_users() as $row) {
    if (!is_array($row)) continue;
    $uname = auth_lower_text((string)($row['username'] ?? ''));
    if ($uname === '') continue;
    if (empty($row['active'])) continue;
    $activeStudents[$uname] = true;
}

$membersInCourse = [];
if (is_array($course)) {
    $membersInCourse = array_values(array_unique(array_filter(array_map(
        static fn($v) => auth_lower_text((string)$v),
        is_array($course['members'] ?? null) ? $course['members'] : []
    ))));
}

$recipients = [];
if ($targetType === 'course') {
    if (!is_array($course)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte Kurs auswählen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $recipients = array_values(array_filter($membersInCourse, static fn($u) => isset($activeStudents[$u])));
} elseif ($targetType === 'users') {
    if (!is_array($usernamesRaw)) {
        http_response_code(400);
        echo json_encode(['error' => 'usernames muss ein Array sein.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $picked = array_values(array_unique(array_filter(array_map(
        static fn($v) => auth_lower_text((string)$v),
        $usernamesRaw
    ))));
    if (!count($picked)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bitte mindestens einen Teilnehmenden auswählen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    foreach ($picked as $uname) {
        if (!isset($activeStudents[$uname])) continue;
        if (!admin_can_access_student_username($uname, $admin)) continue;
        if (is_array($course) && !in_array($uname, $membersInCourse, true)) continue;
        $recipients[] = $uname;
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiger target_type.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$recipients = array_values(array_unique($recipients));
if (!count($recipients)) {
    http_response_code(400);
    echo json_encode(['error' => 'Keine gültigen Empfänger gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Speicherordner konnte nicht erstellt werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$notesFile = $storageDir . '/teacher_notes.json';
$current = [];
if (is_file($notesFile)) {
    $rawNotes = file_get_contents($notesFile);
    $decoded = is_string($rawNotes) ? json_decode($rawNotes, true) : null;
    $current = is_array($decoded) ? $decoded : [];
}

$teacherName = trim((string)($admin['display_name'] ?? ''));
if ($teacherName === '') {
    $teacherName = trim((string)($admin['username'] ?? '')) ?: 'Lehrkraft';
}
$teacherUsername = auth_lower_text((string)($admin['username'] ?? ''));
$now = gmdate('c');
$suffixSeed = bin2hex(random_bytes(3));
$added = [];

foreach ($recipients as $idx => $uname) {
    $id = sprintf('note-%s-%s-%02d', gmdate('YmdHis'), $suffixSeed, $idx + 1);
    $row = [
        'id' => $id,
        'created_at' => $now,
        'student_username' => $uname,
        'note' => $noteText,
        'teacher' => $teacherName,
        'teacher_username' => $teacherUsername,
        'course_id' => is_array($course) ? (string)($course['course_id'] ?? '') : $courseId,
        'target_type' => $targetType,
        'timed' => $timed,
        'duration_minutes' => $durationMinutes,
        'expires_at' => $expiresAt,
    ];
    $current[] = $row;
    $added[] = $row;
}

$json = json_encode(array_values($current), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if (!is_string($json) || file_put_contents($notesFile, $json . PHP_EOL, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Notizen konnten nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('teacher_note_send', [
    'target_type' => $targetType,
    'course_id' => $courseId,
    'recipient_count' => count($recipients),
]);

echo json_encode([
    'ok' => true,
    'sent_count' => count($recipients),
    'course_id' => $courseId,
    'target_type' => $targetType,
], JSON_UNESCAPED_UNICODE);
