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

function read_json_array_file_local(string $file): array
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

function write_json_array_file_local(string $file, array $rows): bool
{
    $json = json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return @file_put_contents($file, $json . PHP_EOL, LOCK_EX) !== false;
}

function append_teacher_note_local(string $storageDir, string $studentUsername, string $noteText, string $teacherName): bool
{
    $file = $storageDir . '/teacher_notes.json';
    $rows = read_json_array_file_local($file);
    try {
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
    } catch (Throwable $e) {
        $suffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    }
    $rows[] = [
        'id' => 'note-' . gmdate('YmdHis') . '-' . $suffix,
        'student_username' => mb_strtolower(trim($studentUsername)),
        'created_at' => gmdate('c'),
        'note' => $noteText,
        'teacher' => $teacherName !== '' ? $teacherName : 'Lehrkraft',
    ];
    return write_json_array_file_local($file, $rows);
}

function reminder_log_path_local(string $storageDir): string
{
    return $storageDir . '/homework_reminders_log.jsonl';
}

function load_reminder_log_local(string $storageDir): array
{
    $file = reminder_log_path_local($storageDir);
    if (!is_file($file)) {
        return [];
    }
    $handle = @fopen($file, 'rb');
    if (!$handle) {
        return [];
    }
    $keys = [];
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }
        $assignmentId = trim((string)($row['assignment_id'] ?? ''));
        $username = mb_strtolower(trim((string)($row['student_username'] ?? '')));
        $level = trim((string)($row['level'] ?? ''));
        if ($assignmentId === '' || $username === '' || $level === '') {
            continue;
        }
        $bucket = trim((string)($row['bucket'] ?? ''));
        $keys[$assignmentId . '|' . $username . '|' . $level . '|' . $bucket] = true;
    }
    fclose($handle);
    return $keys;
}

function load_reminder_history_local(string $storageDir): array
{
    $file = reminder_log_path_local($storageDir);
    if (!is_file($file)) {
        return [];
    }
    $handle = @fopen($file, 'rb');
    if (!$handle) {
        return [];
    }
    $history = [];
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $row = json_decode($line, true);
        if (!is_array($row)) continue;
        $assignmentId = trim((string)($row['assignment_id'] ?? ''));
        $username = mb_strtolower(trim((string)($row['student_username'] ?? '')));
        $level = trim((string)($row['level'] ?? ''));
        $createdAt = trim((string)($row['created_at'] ?? ''));
        if ($assignmentId === '' || $username === '' || $level === '' || $createdAt === '') continue;
        $ts = strtotime($createdAt);
        if ($ts === false) continue;
        $key = $assignmentId . '|' . $username . '|' . $level;
        if (!isset($history[$key]) || !is_array($history[$key])) {
            $history[$key] = [];
        }
        $history[$key][] = (int)$ts;
    }
    fclose($handle);
    foreach ($history as $key => $list) {
        sort($list, SORT_NUMERIC);
        $history[$key] = $list;
    }
    return $history;
}

function reminder_bucket_local(string $level, int $nowTs): string
{
    $safeLevel = trim($level);
    if ($safeLevel === 'warn2') {
        // Allow repeat notifications every 2 hours for near-deadline warnings.
        $hour = (int)gmdate('G', $nowTs);
        $slot = (int)floor($hour / 2);
        return gmdate('Y-m-d', $nowTs) . '-h' . $slot;
    }
    if ($safeLevel === 'warn24' || $safeLevel === 'expired') {
        // Allow one reminder per day for these levels.
        return gmdate('Y-m-d', $nowTs);
    }
    return '';
}

function reminder_can_send_by_policy_local(array $policy, array $historyTs, int $nowTs): bool
{
    if (empty($policy['enabled'])) {
        return false;
    }
    $cooldownHours = max(0, (int)($policy['cooldown_hours'] ?? 0));
    if ($cooldownHours > 0 && $historyTs) {
        $lastTs = (int)end($historyTs);
        if ($lastTs > 0 && ($nowTs - $lastTs) < ($cooldownHours * 3600)) {
            return false;
        }
    }
    $maxPerDay = max(0, (int)($policy['max_per_day'] ?? 0));
    if ($maxPerDay > 0 && $historyTs) {
        $todayKey = gmdate('Y-m-d', $nowTs);
        $countToday = 0;
        foreach ($historyTs as $ts) {
            if (gmdate('Y-m-d', (int)$ts) === $todayKey) {
                $countToday++;
            }
        }
        if ($countToday >= $maxPerDay) {
            return false;
        }
    }
    return true;
}

function append_reminder_log_local(string $storageDir, array $record): bool
{
    $line = json_encode($record, JSON_UNESCAPED_UNICODE);
    if (!is_string($line)) {
        return false;
    }
    return @file_put_contents(reminder_log_path_local($storageDir), $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
}

function reminder_note_text_local(array $assignment, string $level, int $remainingSeconds): string
{
    $title = trim((string)($assignment['title'] ?? 'Aufgabe'));
    if ($level === 'warn2') {
        return "Erinnerung: Ihre Hausaufgabe '{$title}' ist in weniger als 2 Stunden fällig. Bitte reichen Sie sie rechtzeitig ein.";
    }
    if ($level === 'warn24') {
        return "Erinnerung: Ihre Hausaufgabe '{$title}' ist in weniger als 24 Stunden fällig. Bitte planen Sie die Abgabe ein.";
    }
    if ($level === 'expired') {
        return "Hinweis: Die Frist für '{$title}' ist abgelaufen. Bitte kontaktieren Sie Ihre Lehrkraft.";
    }
    if ($remainingSeconds > 0) {
        return "Erinnerung: Hausaufgabe '{$title}' bald fällig.";
    }
    return "Erinnerung: Bitte prüfen Sie Ihre Hausaufgaben im Portal.";
}

$expectedCronToken = getenv('HOMEWORK_REMINDER_CRON_TOKEN') ?: '';
if (defined('HOMEWORK_REMINDER_CRON_TOKEN') && HOMEWORK_REMINDER_CRON_TOKEN !== '') {
    $expectedCronToken = (string)HOMEWORK_REMINDER_CRON_TOKEN;
}
$cronToken = trim((string)($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
$useCronAuth = ($expectedCronToken !== '' && $cronToken !== '' && hash_equals($expectedCronToken, $cronToken));

if ($useCronAuth) {
    $admin = [
        'role' => 'owner',
        'role_key' => 'hauptadmin',
        'username' => 'system-cron',
        'display_name' => 'System',
    ];
} else {
    $admin = require_admin_role_json(['hauptadmin', 'docent']);
}
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$levelsRaw = $body['levels'] ?? ['warn24', 'warn2'];
if (!is_array($levelsRaw)) {
    $levelsRaw = ['warn24', 'warn2'];
}
$requestedLevels = [];
foreach ($levelsRaw as $lvl) {
    $v = trim((string)$lvl);
    if (in_array($v, ['warn24', 'warn2', 'expired'], true)) {
        $requestedLevels[$v] = true;
    }
}
if (!$requestedLevels) {
    $requestedLevels = ['warn24' => true, 'warn2' => true];
}

$dryRun = !empty($body['dry_run']);
$limit = max(1, min(500, (int)($body['limit'] ?? 200)));

$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Storage-Verzeichnis konnte nicht erstellt werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$teacherName = trim((string)($admin['display_name'] ?? ''));
if ($teacherName === '') {
    $teacherName = trim((string)($admin['username'] ?? 'Lehrkraft'));
}

$sentKeys = load_reminder_log_local($storageDir);
$reminderHistory = load_reminder_history_local($storageDir);
$items = load_homework_assignments();
$now = time();
$targets = [];
$createdCount = 0;
$skippedAlreadySent = 0;
$skippedByPolicy = 0;
$policyCache = [];

foreach ($items as $assignment) {
    if (!is_array($assignment)) {
        continue;
    }
    if (!assignment_visibility_for_admin($assignment, $admin)) {
        continue;
    }
    if (mb_strtolower(trim((string)($assignment['status'] ?? 'active'))) !== 'active') {
        continue;
    }

    $assignmentId = trim((string)($assignment['id'] ?? ''));
    if ($assignmentId === '') {
        continue;
    }
    $assignmentTeacher = auth_lower_text((string)($assignment['teacher_username'] ?? ''));
    $policyKey = $assignmentTeacher !== '' ? $assignmentTeacher : $teacherUsername;
    if (!isset($policyCache[$policyKey]) || !is_array($policyCache[$policyKey])) {
        $policyCache[$policyKey] = load_homework_reminder_policy_for_teacher($policyKey);
    }
    $policyProfile = $policyCache[$policyKey];

    $assignees = is_array($assignment['assignees'] ?? null) ? $assignment['assignees'] : [];
    foreach ($assignees as $username => $rawState) {
        if (!is_array($rawState)) {
            continue;
        }
        $uname = mb_strtolower(trim((string)$username));
        if ($uname === '' || !admin_can_access_student_username($uname, $admin)) {
            continue;
        }

        $state = assignment_state_from_raw($assignment, $rawState);
        $reminder = homework_reminder_for_state($state, $now);
        $level = (string)($reminder['level'] ?? 'none');
        if (!isset($requestedLevels[$level])) {
            continue;
        }
        $effectivePolicy = homework_reminder_policy_for_assignment_level($assignment, $level, $policyProfile);
        $historyKey = $assignmentId . '|' . $uname . '|' . $level;
        $historyTs = is_array($reminderHistory[$historyKey] ?? null) ? $reminderHistory[$historyKey] : [];
        if (!reminder_can_send_by_policy_local($effectivePolicy, $historyTs, $now)) {
            $skippedByPolicy++;
            continue;
        }

        $bucket = reminder_bucket_local($level, $now);
        $key = $assignmentId . '|' . $uname . '|' . $level . '|' . $bucket;
        if (!empty($sentKeys[$key])) {
            $skippedAlreadySent++;
            continue;
        }

        $remaining = (int)($reminder['remaining_seconds'] ?? 0);
        $note = reminder_note_text_local($assignment, $level, $remaining);
        $target = [
            'assignment_id' => $assignmentId,
            'student_username' => $uname,
            'level' => $level,
            'remaining_seconds' => $remaining,
            'note' => $note,
        ];
        $targets[] = $target;

        if (!$dryRun) {
            $okNote = append_teacher_note_local($storageDir, $uname, $note, $teacherName);
            if ($okNote) {
                $okLog = append_reminder_log_local($storageDir, [
                    'assignment_id' => $assignmentId,
                    'student_username' => $uname,
                    'level' => $level,
                    'bucket' => $bucket,
                    'created_at' => gmdate('c'),
                    'created_by' => (string)($admin['username'] ?? ''),
                ]);
                if ($okLog) {
                    $sentKeys[$key] = true;
                    if (!isset($reminderHistory[$historyKey]) || !is_array($reminderHistory[$historyKey])) {
                        $reminderHistory[$historyKey] = [];
                    }
                    $reminderHistory[$historyKey][] = $now;
                    $createdCount++;
                }
            }
        }

        if (count($targets) >= $limit) {
            break 2;
        }
    }
}

if (!$dryRun) {
    append_audit_log('homework_reminder_run', [
        'levels' => array_keys($requestedLevels),
        'created' => $createdCount,
        'skipped_already_sent' => $skippedAlreadySent,
        'skipped_by_policy' => $skippedByPolicy,
    ]);
}

echo json_encode([
    'ok' => true,
    'dry_run' => $dryRun,
    'levels' => array_keys($requestedLevels),
    'created' => $createdCount,
    'skipped_already_sent' => $skippedAlreadySent,
    'skipped_by_policy' => $skippedByPolicy,
    'targets' => $targets,
], JSON_UNESCAPED_UNICODE);
