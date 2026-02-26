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

$dateFrom = trim((string)($body['date_from'] ?? ''));
$dateTo = trim((string)($body['date_to'] ?? ''));
$courseId = trim((string)($body['course_id'] ?? ''));
$warn = isset($body['warn_threshold']) ? (float)$body['warn_threshold'] : 20.0;
$crit = isset($body['crit_threshold']) ? (float)$body['crit_threshold'] : 30.0;

$fromTs = $dateFrom !== '' ? strtotime($dateFrom . ' 00:00:00') : null;
$toTs = $dateTo !== '' ? strtotime($dateTo . ' 23:59:59') : null;

$sessionsFile = __DIR__ . '/storage/attendance_sessions.json';
$sessions = [];
if (is_file($sessionsFile)) {
    $rawSessions = file_get_contents($sessionsFile);
    $tmp = is_string($rawSessions) ? json_decode($rawSessions, true) : null;
    if (is_array($tmp)) $sessions = $tmp;
}

$users = load_student_users();
$userMap = [];
foreach ($users as $u) {
    if (!is_array($u)) continue;
    $uname = mb_strtolower(trim((string)($u['username'] ?? '')));
    if ($uname === '') continue;
    $userMap[$uname] = [
        'display_name' => (string)($u['display_name'] ?? ''),
        'email' => (string)($u['email'] ?? ''),
        'phone' => (string)($u['phone'] ?? ''),
        'active' => (bool)($u['active'] ?? false),
    ];
}

$agg = [];
foreach ($sessions as $s) {
    if (!is_array($s)) continue;
    $sidCourse = trim((string)($s['course_id'] ?? ''));
    if ($courseId !== '' && $sidCourse !== $courseId) continue;
    $lessonDate = trim((string)($s['lesson_date'] ?? ''));
    $ts = $lessonDate !== '' ? strtotime($lessonDate . ' 12:00:00') : false;
    if ($fromTs !== null && $ts !== false && $ts < $fromTs) continue;
    if ($toTs !== null && $ts !== false && $ts > $toTs) continue;
    $rows = is_array($s['rows'] ?? null) ? $s['rows'] : [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $u = mb_strtolower(trim((string)($r['username'] ?? '')));
        if ($u === '') continue;
        if (!isset($agg[$u])) {
            $agg[$u] = [
                'total' => 0,
                'missing' => 0,
                'course_name' => (string)($s['course_name'] ?? ''),
            ];
        }
        $agg[$u]['total']++;
        $status = trim((string)($r['status'] ?? ''));
        if ($status === 'absent' || $status === 'excused') {
            $agg[$u]['missing']++;
        }
    }
}

$targets = [];
foreach ($agg as $uname => $info) {
    $total = (int)($info['total'] ?? 0);
    if ($total <= 0) continue;
    $missing = (int)($info['missing'] ?? 0);
    $rate = round(($missing / $total) * 100, 1);
    if ($rate < $warn) continue;
    $severity = $rate > $crit ? 'critical' : 'warning';
    $u = $userMap[$uname] ?? ['display_name' => '', 'email' => '', 'phone' => '', 'active' => false];
    $targets[] = [
        'username' => $uname,
        'display_name' => (string)$u['display_name'],
        'email' => (string)$u['email'],
        'phone' => (string)$u['phone'],
        'active' => (bool)$u['active'],
        'course_name' => (string)($info['course_name'] ?? ''),
        'total' => $total,
        'missing' => $missing,
        'absence_rate' => $rate,
        'severity' => $severity,
    ];
}

usort($targets, static function (array $a, array $b): int {
    return ($b['absence_rate'] <=> $a['absence_rate']);
});

echo json_encode([
    'targets' => $targets,
    'warn_threshold' => $warn,
    'crit_threshold' => $crit,
], JSON_UNESCAPED_UNICODE);
