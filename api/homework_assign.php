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
require_once __DIR__ . '/homework_lib.php';

$admin = require_admin_session_json();
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($body['action'] ?? 'create'));
$items = load_homework_assignments();

if ($action === 'list') {
    $now = time();
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (!assignment_visibility_for_admin($item, $admin)) {
            continue;
        }

        $assignees = is_array($item['assignees'] ?? null) ? $item['assignees'] : [];
        $total = count($assignees);
        $started = 0;
        $submitted = 0;
        $expired = 0;

        foreach ($assignees as $state) {
            if (!is_array($state)) {
                continue;
            }
            $startedAt = trim((string)($state['started_at'] ?? ''));
            $deadlineAt = trim((string)($state['deadline_at'] ?? ''));
            $submittedAt = trim((string)($state['submitted_at'] ?? ''));
            if ($startedAt !== '') {
                $started++;
            }
            if ($submittedAt !== '') {
                $submitted++;
                continue;
            }
            if ($deadlineAt !== '') {
                $deadlineTs = strtotime($deadlineAt);
                if ($deadlineTs !== false && $now >= (int)$deadlineTs) {
                    $expired++;
                }
            }
        }

        $out[] = [
            'id' => (string)($item['id'] ?? ''),
            'title' => (string)($item['title'] ?? ''),
            'description' => (string)($item['description'] ?? ''),
            'attachment' => (string)($item['attachment'] ?? ''),
            'target_type' => (string)($item['target_type'] ?? ''),
            'target_label' => (string)($item['target_label'] ?? ''),
            'course_id' => (string)($item['course_id'] ?? ''),
            'duration_minutes' => (int)($item['duration_minutes'] ?? 0),
            'starts_at' => (string)($item['starts_at'] ?? ''),
            'status' => (string)($item['status'] ?? 'active'),
            'teacher_username' => (string)($item['teacher_username'] ?? ''),
            'bamf_code' => (string)($item['bamf_code'] ?? ''),
            'created_at' => (string)($item['created_at'] ?? ''),
            'updated_at' => (string)($item['updated_at'] ?? ''),
            'assigned_total' => $total,
            'started_total' => $started,
            'submitted_total' => $submitted,
            'expired_total' => $expired,
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });

    echo json_encode(['assignments' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'create') {
    $title = trim((string)($body['title'] ?? ''));
    $description = trim((string)($body['description'] ?? ''));
    $attachment = trim((string)($body['attachment'] ?? ''));
    $targetType = trim((string)($body['target_type'] ?? 'course'));
    $courseId = trim((string)($body['course_id'] ?? ''));
    $usernamesRaw = $body['usernames'] ?? [];
    $durationMinutes = (int)($body['duration_minutes'] ?? 0);
    $startsAt = trim((string)($body['starts_at'] ?? ''));

    if ($title === '' || $description === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Titel und Beschreibung sind erforderlich.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $durationMinutes = max(5, min(24 * 60, $durationMinutes));
    if ($startsAt !== '' && strtotime($startsAt) === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungueltige Startzeit.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $targetUsers = [];
    $targetLabel = '';

    if ($targetType === 'course') {
        if ($courseId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Bitte einen Kurs waehlen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $course = find_course_by_id($courseId);
        if (!is_array($course)) {
            http_response_code(404);
            echo json_encode(['error' => 'Kurs nicht gefunden.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!admin_can_access_course_record($course, $admin)) {
            http_response_code(403);
            echo json_encode(['error' => 'Keine Berechtigung fuer diesen Kurs.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $members = is_array($course['members'] ?? null) ? $course['members'] : [];
        foreach ($members as $member) {
            $uname = mb_strtolower(trim((string)$member));
            if ($uname === '' || !preg_match('/^[a-z0-9._-]{3,32}$/', $uname)) {
                continue;
            }
            if (!admin_can_access_student_username($uname, $admin)) {
                continue;
            }
            $targetUsers[] = $uname;
        }
        $targetUsers = array_values(array_unique($targetUsers));
        $targetLabel = (string)($course['name'] ?? $courseId);
    } elseif ($targetType === 'users') {
        if (!is_array($usernamesRaw)) {
            $usernamesRaw = [];
        }
        foreach ($usernamesRaw as $item) {
            $uname = mb_strtolower(trim((string)$item));
            if ($uname === '' || !preg_match('/^[a-z0-9._-]{3,32}$/', $uname)) {
                continue;
            }
            if (!admin_can_access_student_username($uname, $admin)) {
                continue;
            }
            $targetUsers[] = $uname;
        }
        $targetUsers = array_values(array_unique($targetUsers));
        $targetLabel = 'Ausgewählte Schüler';
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Ungueltiger Zuweisungstyp.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$targetUsers) {
        http_response_code(400);
        echo json_encode(['error' => 'Keine gueltigen Ziel-Schueler gefunden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $suffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    }

    $id = 'hw-' . gmdate('YmdHis') . '-' . $suffix;
    $createdAt = gmdate('c');

    $assignees = [];
    foreach ($targetUsers as $uname) {
        $assignees[$uname] = [
            'started_at' => '',
            'deadline_at' => '',
            'submitted_at' => '',
            'submission_count' => 0,
            'last_upload_id' => '',
        ];
    }

    $item = [
        'id' => $id,
        'title' => $title,
        'description' => $description,
        'attachment' => $attachment,
        'target_type' => $targetType,
        'target_label' => $targetLabel,
        'course_id' => $targetType === 'course' ? $courseId : '',
        'usernames' => $targetType === 'users' ? $targetUsers : [],
        'duration_minutes' => $durationMinutes,
        'starts_at' => $startsAt,
        'status' => 'active',
        'teacher_username' => (string)($admin['username'] ?? ''),
        'bamf_code' => normalize_bamf_code((string)($admin['bamf_code'] ?? '')),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'assignees' => $assignees,
    ];

    $items[] = $item;

    if (!write_homework_assignments($items)) {
        http_response_code(500);
        echo json_encode(['error' => 'Aufgabe konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    append_audit_log('homework_assign_create', [
        'assignment_id' => $id,
        'target_type' => $targetType,
        'target_count' => count($targetUsers),
    ]);

    echo json_encode([
        'ok' => true,
        'assignment_id' => $id,
        'target_count' => count($targetUsers),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'set_active') {
    $assignmentId = trim((string)($body['assignment_id'] ?? ''));
    $active = !empty($body['active']);
    if ($assignmentId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'assignment_id fehlt.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $found = false;
    foreach ($items as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        if ((string)($item['id'] ?? '') !== $assignmentId) {
            continue;
        }
        if (!assignment_visibility_for_admin($item, $admin)) {
            http_response_code(403);
            echo json_encode(['error' => 'Keine Berechtigung für diese Aufgabe.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $items[$i]['status'] = $active ? 'active' : 'archived';
        $items[$i]['updated_at'] = gmdate('c');
        $found = true;
        break;
    }

    if (!$found) {
        http_response_code(404);
        echo json_encode(['error' => 'Aufgabe nicht gefunden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!write_homework_assignments($items)) {
        http_response_code(500);
        echo json_encode(['error' => 'Aufgabenstatus konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    append_audit_log('homework_assign_set_active', [
        'assignment_id' => $assignmentId,
        'active' => $active,
    ]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Ungueltige Aktion.'], JSON_UNESCAPED_UNICODE);
