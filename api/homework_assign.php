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
require_once __DIR__ . '/training_set_lib.php';

function homework_used_dtz_questions_file(): string
{
    return __DIR__ . '/storage/homework_used_dtz_questions.json';
}

function load_used_dtz_question_ids(): array
{
    $file = homework_used_dtz_questions_file();
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

function save_used_dtz_question_ids(array $payload): bool
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return file_put_contents(homework_used_dtz_questions_file(), $json . PHP_EOL, LOCK_EX) !== false;
}

function parse_dtz_template_id(string $templateId): ?array
{
    if (preg_match('/^dtz\-(hoeren|lesen)\-teil([1-5])\-fragenpaket$/', $templateId, $m) !== 1) {
        return null;
    }
    $module = (string)$m[1];
    $teil = (int)$m[2];
    if ($module === 'hoeren' && ($teil < 1 || $teil > 4)) {
        return null;
    }
    if ($module === 'lesen' && ($teil < 1 || $teil > 5)) {
        return null;
    }
    return ['module' => $module, 'teil' => $teil];
}

function build_unique_dtz_bundle_from_used(string $module, int $teil, array $used): array
{
    $set = create_training_set($module, 50, false, $teil);
    $items = is_array($set['items'] ?? null) ? $set['items'] : [];
    $bucketKey = $module . '_teil_' . $teil;
    $usedIds = is_array($used[$bucketKey] ?? null) ? $used[$bucketKey] : [];
    $usedLookup = [];
    foreach ($usedIds as $qid) {
        $key = trim((string)$qid);
        if ($key !== '') {
            $usedLookup[$key] = true;
        }
    }

    $available = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $qid = trim((string)($item['template_id'] ?? ''));
        if ($qid === '' || isset($usedLookup[$qid])) {
            continue;
        }
        $available[] = $item;
    }

    $reusedCycle = false;
    if (!$available) {
        // Pool is exhausted for this Teil: start a fresh cycle instead of failing assignment creation.
        $reusedCycle = true;
        $available = array_values(array_filter($items, static function ($item): bool {
            return is_array($item) && trim((string)($item['template_id'] ?? '')) !== '';
        }));
        $usedLookup = [];
    }

    if (!$available) {
        throw new RuntimeException('Für dieses Teil sind aktuell keine Fragen verfügbar.');
    }

    $targetCount = $module === 'hoeren' ? 8 : 10;
    if ($targetCount > count($available)) {
        $targetCount = count($available);
    }
    shuffle($available);
    $picked = array_slice($available, 0, $targetCount);

    foreach ($picked as $row) {
        $qid = trim((string)($row['template_id'] ?? ''));
        if ($qid !== '') {
            $usedLookup[$qid] = true;
        }
    }

    $nextUsedIds = array_keys($usedLookup);
    sort($nextUsedIds, SORT_STRING);
    $used[$bucketKey] = $nextUsedIds;

    return [
        'module' => $module,
        'teil' => $teil,
        'items' => $picked,
        'reused_cycle' => $reusedCycle,
        'used' => $used,
    ];
}

function build_unique_dtz_bundle(string $module, int $teil): array
{
    $used = load_used_dtz_question_ids();
    $bundle = build_unique_dtz_bundle_from_used($module, $teil, $used);
    $nextUsed = is_array($bundle['used'] ?? null) ? $bundle['used'] : $used;
    if (!save_used_dtz_question_ids($nextUsed)) {
        throw new RuntimeException('Fragen-Status konnte nicht gespeichert werden.');
    }
    unset($bundle['used']);
    return $bundle;
}

function format_dtz_bundle_description(string $baseDescription, array $bundle): string
{
    $module = (string)($bundle['module'] ?? '');
    $teil = (int)($bundle['teil'] ?? 0);
    $items = is_array($bundle['items'] ?? null) ? $bundle['items'] : [];
    $reusedCycle = !empty($bundle['reused_cycle']);
    $moduleLabel = $module === 'hoeren' ? 'Hören' : 'Lesen';
    $lines = [];
    $lines[] = trim($baseDescription);
    $lines[] = '';
    $lines[] = '--- Automatisch zugewiesenes Fragenpaket ---';
    $lines[] = "Bereich: {$moduleLabel} Teil {$teil}";
    $lines[] = $reusedCycle
        ? 'Hinweis: Fragenpool wurde neu gestartet (Wiederholung möglich).'
        : 'Hinweis: Diese Fragen wurden für diese Aufgabe neu vergeben (ohne Wiederholung).';
    foreach ($items as $idx => $item) {
        $qid = trim((string)($item['template_id'] ?? ''));
        $question = trim((string)($item['question'] ?? $item['title'] ?? ''));
        if ($qid === '' && $question === '') {
            continue;
        }
        $shortQuestion = mb_substr($question, 0, 180);
        $lines[] = ($idx + 1) . '. [' . $qid . '] ' . $shortQuestion;
    }
    return trim(implode("\n", array_filter($lines, static fn($v) => $v !== null)));
}

$admin = require_admin_role_json(['hauptadmin', 'docent']);
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
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

        $metrics = homework_assignment_metrics($item, $now);

        $out[] = [
            'id' => (string)($item['id'] ?? ''),
            'batch_group_id' => (string)($item['batch_group_id'] ?? ''),
            'batch_group_label' => (string)($item['batch_group_label'] ?? ''),
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
            'created_at' => (string)($item['created_at'] ?? ''),
            'updated_at' => (string)($item['updated_at'] ?? ''),
            'assigned_total' => (int)($metrics['assigned_total'] ?? 0),
            'checklist_required_total' => (int)($metrics['checklist_required_total'] ?? 0),
            'checklist_complete_total' => (int)($metrics['checklist_complete_total'] ?? 0),
            'started_total' => (int)($metrics['started_total'] ?? 0),
            'submitted_total' => (int)($metrics['submitted_total'] ?? 0),
            'expired_total' => (int)($metrics['expired_total'] ?? 0),
            'warning24_total' => (int)($metrics['warning24_total'] ?? 0),
            'warning2_total' => (int)($metrics['warning2_total'] ?? 0),
            'reminder_level' => (string)($metrics['reminder_level'] ?? 'none'),
            'reminder_label' => (string)($metrics['reminder_label'] ?? 'Keine Fristwarnung'),
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });

    echo json_encode(['assignments' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'create_batch') {
    $assignmentsRaw = $body['assignments'] ?? [];
    $targetType = trim((string)($body['target_type'] ?? 'course'));
    $courseId = trim((string)($body['course_id'] ?? ''));
    $usernamesRaw = $body['usernames'] ?? [];
    $durationMinutes = (int)($body['duration_minutes'] ?? 0);
    $startsAt = trim((string)($body['starts_at'] ?? ''));
    $batchGroupId = trim((string)($body['batch_group_id'] ?? ''));
    $batchGroupLabel = trim((string)($body['batch_group_label'] ?? ''));

    if (!is_array($assignmentsRaw) || !$assignmentsRaw) {
        http_response_code(400);
        echo json_encode(['error' => 'assignments ist erforderlich.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (count($assignmentsRaw) > 50) {
        http_response_code(400);
        echo json_encode(['error' => 'Maximal 50 Aufgaben pro Batch erlaubt.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $durationMinutes = max(5, min(24 * 60, $durationMinutes));
    if ($startsAt === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Startzeit ist erforderlich.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strtotime($startsAt) === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Startzeit.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($batchGroupId !== '' && preg_match('/^[a-z0-9._-]{4,80}$/i', $batchGroupId) !== 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Gruppen-ID.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $targetUsers = [];
    $targetLabel = '';
    if ($targetType === 'course') {
        if ($courseId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Bitte einen Kurs wählen.'], JSON_UNESCAPED_UNICODE);
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
            echo json_encode(['error' => 'Keine Berechtigung für diesen Kurs.'], JSON_UNESCAPED_UNICODE);
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
        echo json_encode(['error' => 'Ungültiger Zuweisungstyp.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$targetUsers) {
        http_response_code(400);
        echo json_encode(['error' => 'Keine gültigen Ziel-Schüler gefunden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newItems = [];
    $newIds = [];
    $createdAt = gmdate('c');
    $usedDtz = load_used_dtz_question_ids();
    $usedDtzChanged = false;

    foreach ($assignmentsRaw as $row) {
        if (!is_array($row)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ungültiger assignments-Eintrag.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $templateId = trim((string)($row['template_id'] ?? ''));
        $title = trim((string)($row['title'] ?? ''));
        $description = trim((string)($row['description'] ?? ''));
        $attachment = trim((string)($row['attachment'] ?? ''));
        if ($title === '' || $description === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Titel und Beschreibung sind erforderlich.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $dtzBundle = null;
        $dtzTemplate = parse_dtz_template_id($templateId);
        if (is_array($dtzTemplate)) {
            try {
                $bundleWithUsed = build_unique_dtz_bundle_from_used((string)$dtzTemplate['module'], (int)$dtzTemplate['teil'], $usedDtz);
                $usedDtz = is_array($bundleWithUsed['used'] ?? null) ? $bundleWithUsed['used'] : $usedDtz;
                unset($bundleWithUsed['used']);
                $dtzBundle = $bundleWithUsed;
                $description = format_dtz_bundle_description($description, $dtzBundle);
                $usedDtzChanged = true;
            } catch (Throwable $e) {
                http_response_code(409);
                echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (Throwable $e) {
            $suffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
        }
        $id = 'hw-' . gmdate('YmdHis') . '-' . $suffix;

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

        $newItems[] = [
            'id' => $id,
            'template_id' => $templateId,
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
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'batch_group_id' => $batchGroupId,
            'batch_group_label' => $batchGroupLabel,
            'assignees' => $assignees,
            'dtz_bundle' => $dtzBundle,
        ];
        $newIds[] = $id;
    }

    $nextItems = array_merge($items, $newItems);
    if (!write_homework_assignments($nextItems)) {
        http_response_code(500);
        echo json_encode(['error' => 'Aufgaben konnten nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($usedDtzChanged && !save_used_dtz_question_ids($usedDtz)) {
        http_response_code(500);
        echo json_encode(['error' => 'Fragen-Status konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    append_audit_log('homework_assign_create_batch', [
        'batch_group_id' => $batchGroupId,
        'created_count' => count($newIds),
        'target_count' => count($targetUsers),
    ]);

    echo json_encode([
        'ok' => true,
        'created_count' => count($newIds),
        'assignment_ids' => $newIds,
        'target_count' => count($targetUsers),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'create') {
    $templateId = trim((string)($body['template_id'] ?? ''));
    $title = trim((string)($body['title'] ?? ''));
    $description = trim((string)($body['description'] ?? ''));
    $attachment = trim((string)($body['attachment'] ?? ''));
    $targetType = trim((string)($body['target_type'] ?? 'course'));
    $courseId = trim((string)($body['course_id'] ?? ''));
    $usernamesRaw = $body['usernames'] ?? [];
    $durationMinutes = (int)($body['duration_minutes'] ?? 0);
    $startsAt = trim((string)($body['starts_at'] ?? ''));
    $batchGroupId = trim((string)($body['batch_group_id'] ?? ''));
    $batchGroupLabel = trim((string)($body['batch_group_label'] ?? ''));

    if ($title === '' || $description === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Titel und Beschreibung sind erforderlich.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $durationMinutes = max(5, min(24 * 60, $durationMinutes));
    if ($startsAt === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Startzeit ist erforderlich.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strtotime($startsAt) === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Startzeit.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($batchGroupId !== '' && preg_match('/^[a-z0-9._-]{4,80}$/i', $batchGroupId) !== 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Gruppen-ID.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $targetUsers = [];
    $targetLabel = '';

    if ($targetType === 'course') {
        if ($courseId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Bitte einen Kurs wählen.'], JSON_UNESCAPED_UNICODE);
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
            echo json_encode(['error' => 'Keine Berechtigung für diesen Kurs.'], JSON_UNESCAPED_UNICODE);
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
        echo json_encode(['error' => 'Ungültiger Zuweisungstyp.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$targetUsers) {
        http_response_code(400);
        echo json_encode(['error' => 'Keine gültigen Ziel-Schüler gefunden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dtzBundle = null;
    $dtzTemplate = parse_dtz_template_id($templateId);
    if (is_array($dtzTemplate)) {
        try {
            $dtzBundle = build_unique_dtz_bundle((string)$dtzTemplate['module'], (int)$dtzTemplate['teil']);
            $description = format_dtz_bundle_description($description, $dtzBundle);
        } catch (Throwable $e) {
            http_response_code(409);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
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
        'template_id' => $templateId,
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
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'batch_group_id' => $batchGroupId,
        'batch_group_label' => $batchGroupLabel,
        'assignees' => $assignees,
        'dtz_bundle' => $dtzBundle,
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

if ($action === 'delete') {
    $assignmentId = trim((string)($body['assignment_id'] ?? ''));
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
        unset($items[$i]);
        $found = true;
        break;
    }

    if (!$found) {
        http_response_code(404);
        echo json_encode(['error' => 'Aufgabe nicht gefunden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $items = array_values($items);
    if (!write_homework_assignments($items)) {
        http_response_code(500);
        echo json_encode(['error' => 'Aufgabe konnte nicht gelöscht werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    append_audit_log('homework_assign_delete', [
        'assignment_id' => $assignmentId,
    ]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete_group') {
    $groupId = trim((string)($body['batch_group_id'] ?? ''));
    if ($groupId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'batch_group_id fehlt.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $next = [];
    $removed = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $itemGroupId = trim((string)($item['batch_group_id'] ?? ''));
        if ($itemGroupId !== $groupId) {
            $next[] = $item;
            continue;
        }
        if (!assignment_visibility_for_admin($item, $admin)) {
            $next[] = $item;
            continue;
        }
        $removed++;
    }

    if ($removed <= 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Gruppe nicht gefunden oder keine Berechtigung.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!write_homework_assignments($next)) {
        http_response_code(500);
        echo json_encode(['error' => 'Aufgabengruppe konnte nicht gelöscht werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    append_audit_log('homework_assign_delete_group', [
        'batch_group_id' => $groupId,
        'removed_count' => $removed,
    ]);

    echo json_encode(['ok' => true, 'removed_count' => $removed], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Ungültige Aktion.'], JSON_UNESCAPED_UNICODE);
