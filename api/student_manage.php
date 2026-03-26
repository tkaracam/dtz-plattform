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

$body = require_json_body_or_400(65536);

$action = trim((string)($body['action'] ?? ''));
$username = mb_strtolower(trim((string)($body['username'] ?? '')));
$needsStudentWrite = true;

if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiger Benutzername.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$users = load_student_users();
$foundIndex = -1;
foreach ($users as $i => $user) {
    if (mb_strtolower((string)($user['username'] ?? '')) === $username) {
        $foundIndex = $i;
        break;
    }
}

if ($foundIndex < 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Benutzer nicht gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!admin_can_access_student_record((array)$users[$foundIndex], $admin)) {
    http_response_code(403);
    echo json_encode(['error' => 'Keine Berechtigung für diesen Benutzer.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'reset_password') {
    $newPassword = (string)($body['new_password'] ?? '');
    $pwdCheck = validate_password_policy($newPassword, $username);
    if (empty($pwdCheck['ok'])) {
        http_response_code(400);
        echo json_encode(['error' => (string)($pwdCheck['error'] ?? 'Ungültiges Passwort.')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $existingHash = (string)($users[$foundIndex]['password_hash'] ?? '');
    if ($existingHash !== '' && password_verify($newPassword, $existingHash)) {
        http_response_code(400);
        echo json_encode(['error' => 'Neues Passwort darf nicht dem aktuellen Passwort entsprechen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $users[$foundIndex]['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    $users[$foundIndex]['updated_at'] = gmdate('c');
} elseif ($action === 'set_active') {
    $active = !empty($body['active']);
    $users[$foundIndex]['active'] = $active;
    $users[$foundIndex]['updated_at'] = gmdate('c');
} elseif ($action === 'update_contact') {
    $email = trim((string)($body['email'] ?? ''));
    $phone = trim((string)($body['phone'] ?? ''));
    $users[$foundIndex]['email'] = $email;
    $users[$foundIndex]['phone'] = $phone;
    $users[$foundIndex]['updated_at'] = gmdate('c');
} elseif ($action === 'assign_teacher') {
    require_hauptadmin_session_json();
    $teacherUsername = mb_strtolower(trim((string)($body['teacher_username'] ?? '')));
    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $teacherUsername)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiger Dozent-Benutzername.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $teachers = load_teacher_users();
    $teacherFound = null;
    foreach ($teachers as $teacher) {
        if (!is_array($teacher)) {
            continue;
        }
        if (mb_strtolower(trim((string)($teacher['username'] ?? ''))) !== $teacherUsername) {
            continue;
        }
        $teacherFound = $teacher;
        break;
    }
    if (!is_array($teacherFound)) {
        http_response_code(404);
        echo json_encode(['error' => 'Dozent nicht gefunden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (empty($teacherFound['active'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Dozent ist deaktiviert.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $users[$foundIndex]['teacher_username'] = $teacherUsername;
    $users[$foundIndex]['updated_at'] = gmdate('c');
} elseif ($action === 'set_nickname') {
    if (normalize_admin_role_key((string)($admin['role_key'] ?? '')) !== 'docent') {
        http_response_code(403);
        echo json_encode(['error' => 'Nickname kann nur von Dozenten gesetzt werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $nickname = trim((string)($body['nickname'] ?? ''));
    if (mb_strlen($nickname) > 64) {
        http_response_code(400);
        echo json_encode(['error' => 'Nickname darf maximal 64 Zeichen haben.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!set_docent_student_nickname((string)($admin['username'] ?? ''), $username, $nickname)) {
        http_response_code(500);
        echo json_encode(['error' => 'Nickname konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $needsStudentWrite = false;
} elseif ($action === 'delete') {
    $deletedUser = $users[$foundIndex];
    array_splice($users, $foundIndex, 1);
    if (!write_student_users($users)) {
        http_response_code(500);
        echo json_encode(['error' => 'Änderung konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $removedFromCourses = 0;
    $coursesChanged = false;
    $courses = load_courses();
    foreach ($courses as &$course) {
        if (!is_array($course)) {
            continue;
        }
        if (!admin_is_hauptadmin($admin) && !admin_can_access_course_record($course, $admin)) {
            continue;
        }
        $members = is_array($course['members'] ?? null) ? $course['members'] : [];
        $before = count($members);
        $members = array_values(array_filter($members, static function ($m) use ($username): bool {
            return mb_strtolower(trim((string)$m)) !== $username;
        }));
        if ($before !== count($members)) {
            $course['members'] = $members;
            $coursesChanged = true;
            $removedFromCourses += ($before - count($members));
        }
    }
    unset($course);
    if ($coursesChanged) {
        write_courses($courses);
    }
    remove_student_nickname_for_all_docents($username);
    append_audit_log('student_manage', [
        'username' => $username,
        'action' => $action,
        'removed_from_courses' => $removedFromCourses,
    ]);
    echo json_encode([
        'ok' => true,
        'username' => $username,
        'action' => $action,
        'active' => false,
        'email' => (string)($deletedUser['email'] ?? ''),
        'phone' => (string)($deletedUser['phone'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Aktion.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($needsStudentWrite) {
    if (!write_student_users($users)) {
        http_response_code(500);
        echo json_encode(['error' => 'Änderung konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$auditPayload = [
    'username' => $username,
    'action' => $action,
];
if ($action === 'assign_teacher') {
    $auditPayload['teacher_username'] = (string)($users[$foundIndex]['teacher_username'] ?? '');
}
if ($action === 'set_nickname') {
    $auditPayload['nickname'] = trim((string)($body['nickname'] ?? ''));
}
append_audit_log('student_manage', $auditPayload);

$nicknameOut = '';
if (normalize_admin_role_key((string)($admin['role_key'] ?? '')) === 'docent') {
    $nicknameOut = get_docent_student_nickname((string)($admin['username'] ?? ''), $username);
}
echo json_encode([
    'ok' => true,
    'username' => $username,
    'action' => $action,
    'active' => (bool)($users[$foundIndex]['active'] ?? false),
    'email' => (string)($users[$foundIndex]['email'] ?? ''),
    'phone' => (string)($users[$foundIndex]['phone'] ?? ''),
    'teacher_username' => (string)($users[$foundIndex]['teacher_username'] ?? ''),
    'nickname' => $nicknameOut,
], JSON_UNESCAPED_UNICODE);
