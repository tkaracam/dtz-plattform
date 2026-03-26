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
    if (!is_array($decoded)) {
        return [
            'version' => 2,
            'updated_at' => '',
            'recent' => [],
            'stats' => [],
            'events' => [],
        ];
    }

    // v2 format (detailed tracking)
    if (isset($decoded['version']) && (int)$decoded['version'] >= 2) {
        return [
            'version' => 2,
            'updated_at' => (string)($decoded['updated_at'] ?? ''),
            'recent' => is_array($decoded['recent'] ?? null) ? $decoded['recent'] : [],
            'stats' => is_array($decoded['stats'] ?? null) ? $decoded['stats'] : [],
            'events' => is_array($decoded['events'] ?? null) ? $decoded['events'] : [],
        ];
    }

    // Legacy format: { "<bucket>": ["template_id", ...] }
    $stats = [];
    $recent = [];
    foreach ($decoded as $bucketKey => $ids) {
        if (!is_string($bucketKey) || !is_array($ids)) {
            continue;
        }
        $bucketRecent = [];
        $bucketStats = [];
        foreach ($ids as $qidRaw) {
            $qid = trim((string)$qidRaw);
            if ($qid === '') {
                continue;
            }
            if (!isset($bucketStats[$qid])) {
                $bucketStats[$qid] = [
                    'count' => 1,
                    'last_used_at' => '',
                    'last_assignment_id' => '',
                    'last_teacher' => '',
                    'last_course_id' => '',
                    'last_target_count' => 0,
                ];
                $bucketRecent[] = $qid;
            }
        }
        if ($bucketStats) {
            $stats[$bucketKey] = $bucketStats;
            $recent[$bucketKey] = $bucketRecent;
        }
    }
    return [
        'version' => 2,
        'updated_at' => gmdate('c'),
        'recent' => $recent,
        'stats' => $stats,
        'events' => [],
    ];
}

function save_used_dtz_question_ids(array $payload): bool
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $normalized = [
        'version' => 2,
        'updated_at' => (string)($payload['updated_at'] ?? gmdate('c')),
        'recent' => is_array($payload['recent'] ?? null) ? $payload['recent'] : [],
        'stats' => is_array($payload['stats'] ?? null) ? $payload['stats'] : [],
        'events' => is_array($payload['events'] ?? null) ? $payload['events'] : [],
    ];
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return file_put_contents(homework_used_dtz_questions_file(), $json . PHP_EOL, LOCK_EX) !== false;
}

function dtz_usage_bucket_key(string $module, int $teil): string
{
    return $module . '_teil_' . $teil;
}

function dtz_usage_parse_ts(string $value): int
{
    $ts = strtotime($value);
    return $ts === false ? 0 : (int)$ts;
}

function dtz_usage_compact_recent(array $recent, int $max = 240): array
{
    $out = [];
    foreach ($recent as $qidRaw) {
        $qid = trim((string)$qidRaw);
        if ($qid === '') {
            continue;
        }
        $out[] = $qid;
    }
    if (count($out) > $max) {
        $out = array_slice($out, count($out) - $max);
    }
    return array_values($out);
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

function build_unique_dtz_bundle_from_used(string $module, int $teil, array $used, array $context = []): array
{
    $set = create_training_set($module, 120, false, $teil);
    $items = is_array($set['items'] ?? null) ? $set['items'] : [];
    $bucketKey = dtz_usage_bucket_key($module, $teil);
    $bucketRecent = is_array($used['recent'][$bucketKey] ?? null) ? $used['recent'][$bucketKey] : [];
    $bucketStats = is_array($used['stats'][$bucketKey] ?? null) ? $used['stats'][$bucketKey] : [];
    $bucketRecent = dtz_usage_compact_recent($bucketRecent, 240);
    $recentWindow = array_slice($bucketRecent, -120);
    $recentLookup = [];
    foreach ($recentWindow as $qid) {
        $recentLookup[(string)$qid] = true;
    }

    $availableByQid = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $qid = trim((string)($item['template_id'] ?? ''));
        if ($qid === '') {
            continue;
        }
        if (!isset($availableByQid[$qid])) {
            $availableByQid[$qid] = $item;
        }
    }
    $available = array_values($availableByQid);

    if (!$available) {
        throw new RuntimeException('Für dieses Teil sind aktuell keine Fragen verfügbar.');
    }

    $targetCount = $module === 'hoeren' ? 8 : 10;
    if ($targetCount > count($available)) {
        $targetCount = count($available);
    }
    foreach ($available as $idx => $item) {
        $qid = trim((string)($item['template_id'] ?? ''));
        $stat = is_array($bucketStats[$qid] ?? null) ? $bucketStats[$qid] : [];
        $available[$idx]['__rank_qid'] = $qid;
        $available[$idx]['__rank_recent'] = isset($recentLookup[$qid]) ? 1 : 0;
        $available[$idx]['__rank_count'] = max(0, (int)($stat['count'] ?? 0));
        $available[$idx]['__rank_ts'] = dtz_usage_parse_ts((string)($stat['last_used_at'] ?? ''));
        try {
            $available[$idx]['__rank_rnd'] = random_int(1, PHP_INT_MAX);
        } catch (Throwable $e) {
            $available[$idx]['__rank_rnd'] = mt_rand(1, PHP_INT_MAX);
        }
    }
    usort($available, static function (array $a, array $b): int {
        if ((int)$a['__rank_recent'] !== (int)$b['__rank_recent']) {
            return (int)$a['__rank_recent'] <=> (int)$b['__rank_recent'];
        }
        if ((int)$a['__rank_count'] !== (int)$b['__rank_count']) {
            return (int)$a['__rank_count'] <=> (int)$b['__rank_count'];
        }
        if ((int)$a['__rank_ts'] !== (int)$b['__rank_ts']) {
            return (int)$a['__rank_ts'] <=> (int)$b['__rank_ts'];
        }
        return (int)$a['__rank_rnd'] <=> (int)$b['__rank_rnd'];
    });
    $picked = array_slice($available, 0, $targetCount);
    $pickedQids = [];
    foreach ($picked as &$row) {
        $qid = trim((string)($row['template_id'] ?? ''));
        if ($qid !== '') {
            $pickedQids[] = $qid;
        }
        unset($row['__rank_qid'], $row['__rank_recent'], $row['__rank_count'], $row['__rank_ts'], $row['__rank_rnd']);
    }
    unset($row);

    $nowIso = gmdate('c');
    $assignmentId = trim((string)($context['assignment_id'] ?? ''));
    $teacher = trim((string)($context['teacher_username'] ?? ''));
    $courseId = trim((string)($context['course_id'] ?? ''));
    $targetUsersCount = max(0, (int)($context['target_users_count'] ?? 0));
    foreach ($pickedQids as $qid) {
        $row = is_array($bucketStats[$qid] ?? null) ? $bucketStats[$qid] : [];
        $count = max(0, (int)($row['count'] ?? 0)) + 1;
        $bucketStats[$qid] = [
            'count' => $count,
            'last_used_at' => $nowIso,
            'last_assignment_id' => $assignmentId,
            'last_teacher' => $teacher,
            'last_course_id' => $courseId,
            'last_target_count' => $targetUsersCount,
        ];
        $bucketRecent[] = $qid;
    }
    $bucketRecent = dtz_usage_compact_recent($bucketRecent, 240);
    $used['stats'][$bucketKey] = $bucketStats;
    $used['recent'][$bucketKey] = $bucketRecent;
    $events = is_array($used['events'] ?? null) ? $used['events'] : [];
    foreach ($picked as $row) {
        $events[] = [
            'ts' => $nowIso,
            'bucket' => $bucketKey,
            'module' => $module,
            'teil' => $teil,
            'template_id' => trim((string)($row['template_id'] ?? '')),
            'title' => trim((string)($row['title'] ?? $row['question'] ?? '')),
            'assignment_id' => $assignmentId,
            'teacher_username' => $teacher,
            'course_id' => $courseId,
            'target_users_count' => $targetUsersCount,
        ];
    }
    if (count($events) > 4000) {
        $events = array_slice($events, count($events) - 4000);
    }
    $used['events'] = $events;
    $used['updated_at'] = $nowIso;

    return [
        'module' => $module,
        'teil' => $teil,
        'items' => $picked,
        'reused_cycle' => false,
        'repeat_risk' => array_values(array_filter($pickedQids, static fn($qid) => isset($recentLookup[(string)$qid]))),
        'selection_mode' => 'least_used_then_least_recent',
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
    if (($bundle['selection_mode'] ?? '') === 'least_used_then_least_recent') {
        $repeatRiskCount = is_array($bundle['repeat_risk'] ?? null) ? count($bundle['repeat_risk']) : 0;
        $lines[] = 'Verteilungsmodus: Gleichmäßig (niedrige Wiederholungswahrscheinlichkeit).';
        $lines[] = 'Wiederholungsrisiko in diesem Paket: ' . $repeatRiskCount . ' von ' . count($items);
    }
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

function build_dtz_usage_report(array $usageState): array
{
    $stats = is_array($usageState['stats'] ?? null) ? $usageState['stats'] : [];
    $recent = is_array($usageState['recent'] ?? null) ? $usageState['recent'] : [];
    $events = is_array($usageState['events'] ?? null) ? $usageState['events'] : [];
    $buckets = [];
    foreach ($stats as $bucket => $rows) {
        if (!is_string($bucket) || !is_array($rows)) {
            continue;
        }
        $usageTotal = 0;
        $maxCount = 0;
        $lastUsedAt = '';
        foreach ($rows as $qid => $row) {
            if (!is_string($qid) || !is_array($row)) {
                continue;
            }
            $count = max(0, (int)($row['count'] ?? 0));
            $usageTotal += $count;
            if ($count > $maxCount) {
                $maxCount = $count;
            }
            $ts = (string)($row['last_used_at'] ?? '');
            if ($ts !== '' && strcmp($ts, $lastUsedAt) > 0) {
                $lastUsedAt = $ts;
            }
        }
        $buckets[] = [
            'bucket' => $bucket,
            'question_count' => count($rows),
            'usage_total' => $usageTotal,
            'max_usage_per_question' => $maxCount,
            'recent_window_size' => is_array($recent[$bucket] ?? null) ? count((array)$recent[$bucket]) : 0,
            'last_used_at' => $lastUsedAt,
        ];
    }
    usort($buckets, static fn(array $a, array $b): int => strcmp((string)$a['bucket'], (string)$b['bucket']));
    $eventTail = array_slice($events, -250);
    return [
        'updated_at' => (string)($usageState['updated_at'] ?? ''),
        'bucket_count' => count($buckets),
        'buckets' => $buckets,
        'events_tail' => array_values($eventTail),
    ];
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

if ($action === 'dtz_usage_report') {
    $usageState = load_used_dtz_question_ids();
    echo json_encode([
        'ok' => true,
        'report' => build_dtz_usage_report($usageState),
    ], JSON_UNESCAPED_UNICODE);
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

        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (Throwable $e) {
            $suffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
        }
        $id = 'hw-' . gmdate('YmdHis') . '-' . $suffix;

        $dtzBundle = null;
        $dtzTemplate = parse_dtz_template_id($templateId);
        if (is_array($dtzTemplate)) {
            try {
                $bundleWithUsed = build_unique_dtz_bundle_from_used(
                    (string)$dtzTemplate['module'],
                    (int)$dtzTemplate['teil'],
                    $usedDtz,
                    [
                        'assignment_id' => $id,
                        'teacher_username' => (string)($admin['username'] ?? ''),
                        'course_id' => $targetType === 'course' ? $courseId : '',
                        'target_users_count' => count($targetUsers),
                    ]
                );
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

    $savedBatch = homework_assignments_mutate(static function (array $current) use ($newItems): array {
        return array_merge($current, $newItems);
    });
    if (!$savedBatch) {
        http_response_code(500);
        echo json_encode(['error' => 'Aufgaben konnten nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($usedDtzChanged && !save_used_dtz_question_ids($usedDtz)) {
        $newIdSet = array_flip($newIds);
        $rolledBack = homework_assignments_mutate(static function (array $current) use ($newIdSet): array {
            return array_values(array_filter($current, static function ($row) use ($newIdSet): bool {
                $id = trim((string)($row['id'] ?? ''));
                return $id === '' || !isset($newIdSet[$id]);
            }));
        });
        http_response_code(500);
        echo json_encode([
            'error' => $rolledBack
                ? 'Fragen-Status konnte nicht gespeichert werden. Batch wurde zurückgerollt.'
                : 'Fragen-Status konnte nicht gespeichert werden und Rollback ist fehlgeschlagen. Bitte sofort prüfen.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dtzAuditRows = array_values(array_filter($newItems, static fn($row) => is_array($row) && is_array($row['dtz_bundle'] ?? null)));
    $dtzAuditRows = array_values(array_map(static function (array $row): array {
        $bundle = is_array($row['dtz_bundle'] ?? null) ? $row['dtz_bundle'] : [];
        return [
            'assignment_id' => (string)($row['id'] ?? ''),
            'template_id' => (string)($row['template_id'] ?? ''),
            'selection_mode' => (string)($bundle['selection_mode'] ?? ''),
            'repeat_risk_count' => is_array($bundle['repeat_risk'] ?? null) ? count($bundle['repeat_risk']) : 0,
            'bundle_size' => is_array($bundle['items'] ?? null) ? count($bundle['items']) : 0,
        ];
    }, $dtzAuditRows));

    append_audit_log('homework_assign_create_batch', [
        'batch_group_id' => $batchGroupId,
        'created_count' => count($newIds),
        'target_count' => count($targetUsers),
        'dtz_assignments' => $dtzAuditRows,
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

    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $suffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    }

    $id = 'hw-' . gmdate('YmdHis') . '-' . $suffix;
    $createdAt = gmdate('c');

    $dtzBundle = null;
    $dtzTemplate = parse_dtz_template_id($templateId);
    if (is_array($dtzTemplate)) {
        $usedDtz = load_used_dtz_question_ids();
        try {
            $bundleWithUsed = build_unique_dtz_bundle_from_used(
                (string)$dtzTemplate['module'],
                (int)$dtzTemplate['teil'],
                $usedDtz,
                [
                    'assignment_id' => $id,
                    'teacher_username' => (string)($admin['username'] ?? ''),
                    'course_id' => $targetType === 'course' ? $courseId : '',
                    'target_users_count' => count($targetUsers),
                ]
            );
            $nextUsed = is_array($bundleWithUsed['used'] ?? null) ? $bundleWithUsed['used'] : $usedDtz;
            if (!save_used_dtz_question_ids($nextUsed)) {
                http_response_code(500);
                echo json_encode(['error' => 'Fragen-Status konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            unset($bundleWithUsed['used']);
            $dtzBundle = $bundleWithUsed;
            $description = format_dtz_bundle_description($description, $dtzBundle);
        } catch (Throwable $e) {
            http_response_code(409);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

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

    if (!homework_assignments_mutate(static function (array $current) use ($item): array {
        $current[] = $item;
        return $current;
    })) {
        http_response_code(500);
        echo json_encode(['error' => 'Aufgabe konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    append_audit_log('homework_assign_create', [
        'assignment_id' => $id,
        'target_type' => $targetType,
        'target_count' => count($targetUsers),
        'dtz_selection_mode' => is_array($dtzBundle) ? (string)($dtzBundle['selection_mode'] ?? '') : '',
        'dtz_repeat_risk_count' => is_array($dtzBundle) && is_array($dtzBundle['repeat_risk'] ?? null) ? count($dtzBundle['repeat_risk']) : 0,
        'dtz_bundle_size' => is_array($dtzBundle) && is_array($dtzBundle['items'] ?? null) ? count($dtzBundle['items']) : 0,
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

    $setState = ['found' => false, 'forbidden' => false];
    if (!homework_assignments_mutate(function (array $items) use ($assignmentId, $active, $admin, &$setState): array|false {
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string)($item['id'] ?? '') !== $assignmentId) {
                continue;
            }
            if (!assignment_visibility_for_admin($item, $admin)) {
                $setState['forbidden'] = true;
                return false;
            }
            $items[$i]['status'] = $active ? 'active' : 'archived';
            $items[$i]['updated_at'] = gmdate('c');
            $setState['found'] = true;
            return $items;
        }
        return false;
    })) {
        http_response_code(500);
        echo json_encode(['error' => 'Aufgabenstatus konnte nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($setState['forbidden']) {
        http_response_code(403);
        echo json_encode(['error' => 'Keine Berechtigung für diese Aufgabe.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$setState['found']) {
        http_response_code(404);
        echo json_encode(['error' => 'Aufgabe nicht gefunden.'], JSON_UNESCAPED_UNICODE);
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

    $delState = ['found' => false, 'forbidden' => false];
    if (!homework_assignments_mutate(function (array $items) use ($assignmentId, $admin, &$delState): array|false {
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string)($item['id'] ?? '') !== $assignmentId) {
                continue;
            }
            if (!assignment_visibility_for_admin($item, $admin)) {
                $delState['forbidden'] = true;
                return false;
            }
            unset($items[$i]);
            $delState['found'] = true;
            return array_values($items);
        }
        return false;
    })) {
        http_response_code(500);
        echo json_encode(['error' => 'Aufgabe konnte nicht gelöscht werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($delState['forbidden']) {
        http_response_code(403);
        echo json_encode(['error' => 'Keine Berechtigung für diese Aufgabe.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$delState['found']) {
        http_response_code(404);
        echo json_encode(['error' => 'Aufgabe nicht gefunden.'], JSON_UNESCAPED_UNICODE);
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

    $grpState = ['removed' => 0];
    if (!homework_assignments_mutate(function (array $items) use ($groupId, $admin, &$grpState): array|false {
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
            return false;
        }
        $grpState['removed'] = $removed;
        return $next;
    })) {
        http_response_code(500);
        echo json_encode(['error' => 'Aufgabengruppe konnte nicht gelöscht werden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($grpState['removed'] <= 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Gruppe nicht gefunden oder keine Berechtigung.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $removed = $grpState['removed'];

    append_audit_log('homework_assign_delete_group', [
        'batch_group_id' => $groupId,
        'removed_count' => $removed,
    ]);

    echo json_encode(['ok' => true, 'removed_count' => $removed], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Ungültige Aktion.'], JSON_UNESCAPED_UNICODE);
