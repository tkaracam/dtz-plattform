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
require_once __DIR__ . '/homework_lib.php';
$admin = require_admin_role_json(['hauptadmin', 'docent']);

function read_json_array_file_course_manage(string $file): array
{
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

function write_json_array_file_course_manage(string $file, array $rows): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return file_put_contents($file, $json . PHP_EOL, LOCK_EX) !== false;
}

function rewrite_jsonl_file_course_manage(string $file, callable $keep, ?int &$removed = null): bool
{
    $removed = 0;
    if (!is_file($file)) {
        return true;
    }
    $handle = @fopen($file, 'rb');
    if (!$handle) {
        return false;
    }
    $keptLines = [];
    while (($line = fgets($handle)) !== false) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        $row = json_decode($trimmed, true);
        if (!is_array($row)) {
            continue;
        }
        if ($keep($row)) {
            $keptLines[] = json_encode($row, JSON_UNESCAPED_UNICODE);
        } else {
            $removed++;
        }
    }
    fclose($handle);
    $payload = $keptLines ? (implode(PHP_EOL, $keptLines) . PHP_EOL) : '';
    return file_put_contents($file, $payload, LOCK_EX) !== false;
}

function cleanup_course_related_data(string $courseId, array $deletedUsernames, array &$stats): bool
{
    $deletedUserMap = [];
    foreach ($deletedUsernames as $uname) {
        $key = auth_lower_text((string)$uname);
        if ($key !== '') {
            $deletedUserMap[$key] = true;
        }
    }

    $stats = [
        'homework_removed' => 0,
        'homework_attempts_removed' => 0,
        'letters_removed' => 0,
        'letter_reviews_removed' => 0,
        'teacher_notes_removed' => 0,
        'attendance_sessions_removed' => 0,
        'attendance_requests_removed' => 0,
        'reminder_logs_removed' => 0,
    ];

    $items = load_homework_assignments();
    $nextItems = [];
    $removedAssignmentIds = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (trim((string)($row['course_id'] ?? '')) === $courseId) {
            $assignmentId = trim((string)($row['id'] ?? ''));
            if ($assignmentId !== '') {
                $removedAssignmentIds[$assignmentId] = true;
            }
            $stats['homework_removed']++;
            continue;
        }
        $nextItems[] = $row;
    }
    if ($stats['homework_removed'] > 0 && !write_homework_assignments($nextItems)) {
        return false;
    }

    $storageDir = __DIR__ . '/storage';
    $attemptsFile = $storageDir . '/homework_attempts.jsonl';
    if (!rewrite_jsonl_file_course_manage(
        $attemptsFile,
        static function (array $row) use ($removedAssignmentIds, $deletedUserMap): bool {
            $assignmentId = trim((string)($row['assignment_id'] ?? ''));
            if ($assignmentId !== '' && isset($removedAssignmentIds[$assignmentId])) {
                return false;
            }
            $studentUsername = auth_lower_text((string)($row['student_username'] ?? ''));
            if ($studentUsername !== '' && isset($deletedUserMap[$studentUsername])) {
                return false;
            }
            return true;
        },
        $stats['homework_attempts_removed']
    )) {
        return false;
    }

    $reminderLogFile = $storageDir . '/homework_reminders_log.jsonl';
    if (!rewrite_jsonl_file_course_manage(
        $reminderLogFile,
        static function (array $row) use ($removedAssignmentIds): bool {
            $assignmentId = trim((string)($row['assignment_id'] ?? ''));
            return $assignmentId === '' || !isset($removedAssignmentIds[$assignmentId]);
        },
        $stats['reminder_logs_removed']
    )) {
        return false;
    }

    $removedUploadIds = [];
    foreach (glob($storageDir . '/letters-*.jsonl') ?: [] as $lettersFile) {
        $removedHere = 0;
        $ok = rewrite_jsonl_file_course_manage(
            (string)$lettersFile,
            static function (array $row) use ($removedAssignmentIds, $deletedUserMap, &$removedUploadIds): bool {
                $assignmentId = trim((string)($row['assignment_id'] ?? ''));
                $studentUsername = auth_lower_text((string)($row['student_username'] ?? ''));
                if ($assignmentId !== '' && isset($removedAssignmentIds[$assignmentId])) {
                    $uploadId = trim((string)($row['upload_id'] ?? ''));
                    if ($uploadId !== '') {
                        $removedUploadIds[$uploadId] = true;
                    }
                    return false;
                }
                if ($studentUsername !== '' && isset($deletedUserMap[$studentUsername])) {
                    $uploadId = trim((string)($row['upload_id'] ?? ''));
                    if ($uploadId !== '') {
                        $removedUploadIds[$uploadId] = true;
                    }
                    return false;
                }
                return true;
            },
            $removedHere
        );
        if (!$ok) {
            return false;
        }
        $stats['letters_removed'] += (int)$removedHere;
    }

    $letterReviewsFile = $storageDir . '/letter_reviews.jsonl';
    if (!rewrite_jsonl_file_course_manage(
        $letterReviewsFile,
        static function (array $row) use ($removedUploadIds): bool {
            $uploadId = trim((string)($row['upload_id'] ?? ''));
            return $uploadId === '' || !isset($removedUploadIds[$uploadId]);
        },
        $stats['letter_reviews_removed']
    )) {
        return false;
    }

    $notesFile = $storageDir . '/teacher_notes.json';
    $notes = read_json_array_file_course_manage($notesFile);
    if (count($notes) > 0) {
        $nextNotes = [];
        foreach ($notes as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowCourseId = trim((string)($row['course_id'] ?? ''));
            $rowStudent = auth_lower_text((string)($row['student_username'] ?? ''));
            if ($rowCourseId === $courseId || ($rowStudent !== '' && isset($deletedUserMap[$rowStudent]))) {
                $stats['teacher_notes_removed']++;
                continue;
            }
            $nextNotes[] = $row;
        }
        if ($stats['teacher_notes_removed'] > 0 && !write_json_array_file_course_manage($notesFile, $nextNotes)) {
            return false;
        }
    }

    $attendanceFile = $storageDir . '/attendance_sessions.json';
    $attendanceRows = read_json_array_file_course_manage($attendanceFile);
    $removedSessionIds = [];
    if (count($attendanceRows) > 0) {
        $nextAttendance = [];
        foreach ($attendanceRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string)($row['course_id'] ?? '')) === $courseId) {
                $sessionId = trim((string)($row['session_id'] ?? ''));
                if ($sessionId !== '') {
                    $removedSessionIds[$sessionId] = true;
                }
                $stats['attendance_sessions_removed']++;
                continue;
            }
            $nextAttendance[] = $row;
        }
        if ($stats['attendance_sessions_removed'] > 0 && !write_json_array_file_course_manage($attendanceFile, $nextAttendance)) {
            return false;
        }
    }

    if (count($removedSessionIds) > 0) {
        $requestsFile = $storageDir . '/attendance_requests.json';
        $requestsRows = read_json_array_file_course_manage($requestsFile);
        if (count($requestsRows) > 0) {
            $nextRequests = [];
            foreach ($requestsRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sessionId = trim((string)($row['session_id'] ?? ''));
                if ($sessionId !== '' && isset($removedSessionIds[$sessionId])) {
                    $stats['attendance_requests_removed']++;
                    continue;
                }
                $nextRequests[] = $row;
            }
            if ($stats['attendance_requests_removed'] > 0 && !write_json_array_file_course_manage($requestsFile, $nextRequests)) {
                return false;
            }
        }
    }

    return true;
}

function delete_student_accounts_for_course_members(array $usernames, array &$stats): bool
{
    $stats['student_accounts_removed'] = 0;
    if (count($usernames) === 0) {
        return true;
    }
    $targetMap = [];
    foreach ($usernames as $uname) {
        $key = auth_lower_text((string)$uname);
        if ($key !== '') {
            $targetMap[$key] = true;
        }
    }
    if (count($targetMap) === 0) {
        return true;
    }

    $students = load_student_users();
    $nextStudents = [];
    foreach ($students as $row) {
        if (!is_array($row)) {
            continue;
        }
        $uname = auth_lower_text((string)($row['username'] ?? ''));
        if ($uname !== '' && isset($targetMap[$uname])) {
            $stats['student_accounts_removed']++;
            continue;
        }
        $nextStudents[] = $row;
    }

    if ($stats['student_accounts_removed'] > 0 && !write_student_users($nextStudents)) {
        return false;
    }

    foreach (array_keys($targetMap) as $uname) {
        remove_student_nickname_for_all_docents((string)$uname);
    }

    return true;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
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
    $idPrefix = 'kurs';
    $id = $idPrefix . '-' . $suffix;
    $courses[] = [
        'course_id' => $id,
        'name' => $name,
        'level' => $level,
        'schedule' => $schedule,
        'teacher_username' => (string)($admin['username'] ?? ''),
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
    if (($admin['role'] ?? '') === 'docent') {
        $members = array_values(array_filter($members, function ($u) use ($admin): bool {
            return admin_can_access_student_username((string)$u, $admin);
        }));
    }
    $found = false;
    foreach ($courses as &$c) {
        if (!is_array($c)) continue;
        if ((string)($c['course_id'] ?? '') !== $courseId) continue;
        if (!admin_can_access_course_record($c, $admin)) {
            http_response_code(403);
            echo json_encode(['error' => 'Keine Berechtigung für diesen Kurs.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
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
        if (!admin_can_access_course_record($c, $admin)) {
            http_response_code(403);
            echo json_encode(['error' => 'Keine Berechtigung für diesen Kurs.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
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
} elseif ($action === 'delete') {
    $courseId = trim((string)($body['course_id'] ?? ''));
    if ($courseId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'course_id ist erforderlich.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $found = false;
    $nextCourses = [];
    $cleanupStats = [];
    $deletedMembers = [];
    foreach ($courses as $c) {
        if (!is_array($c)) {
            continue;
        }
        if ((string)($c['course_id'] ?? '') !== $courseId) {
            $nextCourses[] = $c;
            continue;
        }
        if (!admin_can_access_course_record($c, $admin)) {
            http_response_code(403);
            echo json_encode(['error' => 'Keine Berechtigung für diesen Kurs.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $members = is_array($c['members'] ?? null) ? $c['members'] : [];
        foreach ($members as $member) {
            $uname = auth_lower_text((string)$member);
            if ($uname !== '') {
                $deletedMembers[$uname] = true;
            }
        }
        $found = true;
    }
    if (!$found) {
        http_response_code(404);
        echo json_encode(['error' => 'Kurs nicht gefunden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $deletedMemberUsernames = array_keys($deletedMembers);

    if (!cleanup_course_related_data($courseId, $deletedMemberUsernames, $cleanupStats)) {
        http_response_code(500);
        echo json_encode(['error' => 'Kursbezogene Daten konnten nicht vollständig gelöscht werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!delete_student_accounts_for_course_members($deletedMemberUsernames, $cleanupStats)) {
        http_response_code(500);
        echo json_encode(['error' => 'Teilnehmerkonten konnten nicht vollständig gelöscht werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cleanupStats['memberships_removed_other_courses'] = 0;
    if (count($deletedMemberUsernames) > 0) {
        $memberMap = array_fill_keys($deletedMemberUsernames, true);
        foreach ($nextCourses as &$courseRow) {
            if (!is_array($courseRow)) {
                continue;
            }
            $members = is_array($courseRow['members'] ?? null) ? $courseRow['members'] : [];
            $before = count($members);
            $members = array_values(array_filter($members, static function ($m) use ($memberMap): bool {
                $uname = auth_lower_text((string)$m);
                return $uname === '' || !isset($memberMap[$uname]);
            }));
            if ($before !== count($members)) {
                $cleanupStats['memberships_removed_other_courses'] += ($before - count($members));
                $courseRow['members'] = $members;
                $courseRow['updated_at'] = gmdate('c');
            }
        }
        unset($courseRow);
    }

    $courses = $nextCourses;
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Aktion.'], JSON_UNESCAPED_UNICODE);
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
    'cleanup' => isset($cleanupStats) && is_array($cleanupStats) ? $cleanupStats : null,
]);

$out = ['ok' => true];
if (isset($id) && is_string($id) && $id !== '') {
    $out['course_id'] = $id;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
