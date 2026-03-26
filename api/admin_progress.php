<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
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
require_once __DIR__ . '/homework_lib.php';
require_once __DIR__ . '/letter_reviews.php';

$admin = require_admin_role_json(['hauptadmin', 'docent']);

function progress_normalize_username(string $value): string
{
    return mb_strtolower(trim($value));
}

function progress_iso_ts(string $value): int
{
    $raw = trim($value);
    if ($raw === '') {
        return 0;
    }
    $ts = strtotime($raw);
    return $ts === false ? 0 : (int)$ts;
}

function progress_read_letters(string $storageDir): array
{
    $files = glob($storageDir . '/letters-*.jsonl') ?: [];
    rsort($files, SORT_STRING);
    $rows = [];
    foreach ($files as $file) {
        $handle = @fopen($file, 'rb');
        if (!$handle) {
            continue;
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        fclose($handle);
    }
    return $rows;
}

function progress_extract_score(array $review): ?int
{
    $result = is_array($review['correction_result'] ?? null) ? $review['correction_result'] : null;
    if (!$result || !isset($result['score_total'])) {
        return null;
    }
    return (int)$result['score_total'];
}

function progress_read_jsonl_file(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $rows = [];
    $handle = @fopen($file, 'rb');
    if (!$handle) {
        return [];
    }
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    fclose($handle);
    return $rows;
}

$courseIdFilter = trim((string)($_GET['course_id'] ?? ''));
$studentUsernameFilter = progress_normalize_username((string)($_GET['student_username'] ?? ''));
$windowDays = (int)($_GET['days'] ?? 7);
if ($windowDays < 1 || $windowDays > 90) {
    $windowDays = 7;
}
$nowTs = time();
$windowEndTs = $nowTs;
$windowStartTs = $nowTs - ($windowDays * 86400);

$rangeFromRaw = trim((string)($_GET['from'] ?? ''));
$rangeToRaw = trim((string)($_GET['to'] ?? ''));
$rangeFromTs = $rangeFromRaw !== '' ? strtotime($rangeFromRaw . ' 00:00:00') : false;
$rangeToTs = $rangeToRaw !== '' ? strtotime($rangeToRaw . ' 23:59:59') : false;
if ($rangeFromTs !== false || $rangeToTs !== false) {
    $effectiveFromTs = $rangeFromTs !== false ? (int)$rangeFromTs : (int)($nowTs - ($windowDays * 86400));
    $effectiveToTs = $rangeToTs !== false ? (int)$rangeToTs : $nowTs;
    if ($effectiveFromTs > $effectiveToTs) {
        [$effectiveFromTs, $effectiveToTs] = [$effectiveToTs, $effectiveFromTs];
    }
    $windowStartTs = $effectiveFromTs;
    $windowEndTs = $effectiveToTs;
    $windowDays = max(1, (int)floor(($windowEndTs - $windowStartTs) / 86400) + 1);
    $rangeFromRaw = gmdate('Y-m-d', $windowStartTs);
    $rangeToRaw = gmdate('Y-m-d', $windowEndTs);
} else {
    $rangeFromRaw = '';
    $rangeToRaw = '';
}

$trendEndOfDayTs = strtotime(gmdate('Y-m-d', $windowEndTs) . ' 23:59:59');
$trendBuckets = [];
for ($idx = 0; $idx < 4; $idx++) {
    $weeksFromNewest = 3 - $idx;
    $bucketEndTs = $trendEndOfDayTs - ($weeksFromNewest * 7 * 86400);
    $bucketStartTs = $bucketEndTs - (7 * 86400) + 1;
    $trendBuckets[$idx] = [
        'index' => $idx,
        'start_ts' => $bucketStartTs,
        'end_ts' => $bucketEndTs,
        'label' => gmdate('d.m', $bucketStartTs) . '–' . gmdate('d.m', $bucketEndTs),
        'assigned' => 0,
        'submitted' => 0,
        'avg_score' => null,
        '_score_sum' => 0,
        '_score_count' => 0,
    ];
}

$trendBucketIndexForTs = static function (int $eventTs) use ($trendBuckets): ?int {
    if ($eventTs <= 0) {
        return null;
    }
    foreach ($trendBuckets as $bucket) {
        if ($eventTs >= (int)$bucket['start_ts'] && $eventTs <= (int)$bucket['end_ts']) {
            return (int)$bucket['index'];
        }
    }
    return null;
};

$allCourses = load_courses();
$visibleCourses = [];
foreach ($allCourses as $course) {
    if (!is_array($course)) {
        continue;
    }
    if (!admin_can_access_course_record($course, $admin)) {
        continue;
    }
    $courseId = trim((string)($course['course_id'] ?? ''));
    if ($courseId === '') {
        continue;
    }
    $visibleCourses[$courseId] = $course;
}

if ($courseIdFilter !== '' && !isset($visibleCourses[$courseIdFilter])) {
    http_response_code(404);
    echo json_encode(['error' => 'Kurs wurde nicht gefunden oder Zugriff verweigert.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$visibleStudents = [];
foreach (load_student_users() as $student) {
    if (!is_array($student)) {
        continue;
    }
    if (!admin_can_access_student_record($student, $admin)) {
        continue;
    }
    $uname = progress_normalize_username((string)($student['username'] ?? ''));
    if ($uname === '') {
        continue;
    }
    $visibleStudents[$uname] = $student;
}

if ($studentUsernameFilter !== '' && !isset($visibleStudents[$studentUsernameFilter])) {
    http_response_code(404);
    echo json_encode(['error' => 'Teilnehmende wurde nicht gefunden oder Zugriff verweigert.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$studentStats = [];
foreach ($visibleStudents as $uname => $student) {
    $studentName = trim((string)($student['display_name'] ?? $student['name'] ?? ''));
    $studentStats[$uname] = [
        'student_username' => (string)($student['username'] ?? $uname),
        'student_name' => $studentName,
        'assigned_window' => 0,
        'submitted_window' => 0,
        'pending_homeworks' => 0,
        'dtz_correct_window' => 0,
        'dtz_wrong_window' => 0,
        'dtz_total_window' => 0,
        'last_submitted_at' => '',
        'avg_score_window' => null,
        '_score_sum_window' => 0,
        '_score_count_window' => 0,
        '_last_submitted_ts' => 0,
    ];
}

$courseMembers = [];
$studentCourseMap = [];
foreach ($visibleCourses as $courseId => $course) {
    $members = [];
    foreach ((array)($course['members'] ?? []) as $member) {
        $uname = progress_normalize_username((string)$member);
        if ($uname === '') {
            continue;
        }
        $members[$uname] = true;
        $studentCourseMap[$uname] = $studentCourseMap[$uname] ?? [];
        $studentCourseMap[$uname][$courseId] = true;
    }
    $courseMembers[$courseId] = $members;
}

if ($courseIdFilter !== '') {
    $onlyMembers = $courseMembers[$courseIdFilter] ?? [];
    foreach (array_keys($visibleStudents) as $uname) {
        if (!isset($onlyMembers[$uname])) {
            unset($visibleStudents[$uname]);
        }
    }
}

if ($studentUsernameFilter !== '') {
    foreach (array_keys($visibleStudents) as $uname) {
        if ($uname !== $studentUsernameFilter) {
            unset($visibleStudents[$uname]);
        }
    }
    if (!$visibleStudents) {
        http_response_code(404);
        echo json_encode(['error' => 'Teilnehmende passt nicht zum gewählten Kursfilter.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

foreach (array_keys($studentStats) as $uname) {
    if (!isset($visibleStudents[$uname])) {
        unset($studentStats[$uname]);
    }
}

$courseStats = [];
foreach ($visibleCourses as $courseId => $course) {
    if ($courseIdFilter !== '' && $courseId !== $courseIdFilter) {
        continue;
    }
    $courseStats[$courseId] = [
        'course_id' => $courseId,
        'name' => (string)($course['name'] ?? $courseId),
        'level' => (string)($course['level'] ?? ''),
        'schedule' => (string)($course['schedule'] ?? ''),
        'active' => !empty($course['active']),
        'members_total' => count($courseMembers[$courseId] ?? []),
        'homeworks_total' => 0,
        'active_homeworks' => 0,
        'assigned_window' => 0,
        'submitted_window' => 0,
        'pending_reviews' => 0,
        'reminders_sent_window' => 0,
        'avg_score_window' => null,
        '_score_sum_window' => 0,
        '_score_count_window' => 0,
    ];
}

$visibleAssignments = [];
$visibleAssignmentsById = [];
foreach (load_homework_assignments() as $assignment) {
    if (!is_array($assignment)) {
        continue;
    }
    if (!assignment_visibility_for_admin($assignment, $admin)) {
        continue;
    }
    $visibleAssignments[] = $assignment;
    $aid = trim((string)($assignment['id'] ?? ''));
    if ($aid !== '') {
        $visibleAssignmentsById[$aid] = $assignment;
    }
}

$recentModelltestResults = [];
foreach ($visibleAssignments as $assignment) {
    if (!is_array($assignment)) {
        continue;
    }
    $templateId = mb_strtolower(trim((string)($assignment['template_id'] ?? '')));
    if ($templateId !== 'dtz-mock-pruefung-komplett') {
        continue;
    }
    $assignmentCourseId = trim((string)($assignment['course_id'] ?? ''));
    $assignees = is_array($assignment['assignees'] ?? null) ? $assignment['assignees'] : [];
    foreach ($assignees as $assigneeUsername => $state) {
        $uname = progress_normalize_username((string)$assigneeUsername);
        if ($uname === '' || !isset($studentStats[$uname])) {
            continue;
        }
        $belongs = $studentCourseMap[$uname] ?? [];
        if ($courseIdFilter !== '' && !isset($belongs[$courseIdFilter])) {
            continue;
        }
        $result = is_array($state['last_modelltest_result'] ?? null) ? $state['last_modelltest_result'] : null;
        if (!$result) {
            continue;
        }
        $savedAt = (string)($result['saved_at'] ?? '');
        $savedTs = progress_iso_ts($savedAt);
        if ($savedTs <= 0 || $savedTs < $windowStartTs || $savedTs > $windowEndTs) {
            continue;
        }
        $studentName = trim((string)($studentStats[$uname]['student_name'] ?? ''));
        $recentModelltestResults[] = [
            'saved_at' => $savedAt,
            'course_id' => $assignmentCourseId,
            'student_username' => (string)($studentStats[$uname]['student_username'] ?? $uname),
            'student_name' => $studentName,
            'hoeren_correct' => (int)($result['hoeren_correct'] ?? 0),
            'hoeren_total' => (int)($result['hoeren_total'] ?? 0),
            'lesen_correct' => (int)($result['lesen_correct'] ?? 0),
            'lesen_total' => (int)($result['lesen_total'] ?? 0),
            'schreiben_score' => (int)($result['schreiben_score'] ?? 0),
            'schreiben_max' => (int)($result['schreiben_max'] ?? 0),
            'overall_percent' => (int)($result['overall_percent'] ?? 0),
            'level' => (string)($result['level'] ?? 'A1')
        ];
    }
}

$summaryAssignedWindow = 0;
$summarySubmittedWindow = 0;
$summaryActiveHomeworks = 0;
$summaryRemindersWindow = 0;
$remindersByCourse = [];
$remindersByTemplate = [];
$remindersByLevel = [
    'warn24' => 0,
    'warn2' => 0,
    'expired' => 0,
    'other' => 0,
];

foreach ($visibleAssignments as $assignment) {
    $assignees = is_array($assignment['assignees'] ?? null) ? $assignment['assignees'] : [];
    $assignmentCourseId = trim((string)($assignment['course_id'] ?? ''));
    $createdTs = progress_iso_ts((string)($assignment['created_at'] ?? ''));
    $createdInWindow = $createdTs > 0 && $createdTs >= $windowStartTs && $createdTs <= $windowEndTs;
    $assignmentIsActive = assignment_is_active_now($assignment, $nowTs);

    $impactCourseIds = [];
    if ($assignmentCourseId !== '' && isset($courseStats[$assignmentCourseId])) {
        $impactCourseIds[$assignmentCourseId] = true;
    }
    foreach (array_keys($assignees) as $assigneeUsername) {
        $uname = progress_normalize_username((string)$assigneeUsername);
        if ($uname === '') {
            continue;
        }
        $belongs = $studentCourseMap[$uname] ?? [];
        foreach (array_keys($belongs) as $cid) {
            if (!isset($courseStats[$cid])) {
                continue;
            }
            $impactCourseIds[$cid] = true;
        }
    }

    if (!$impactCourseIds) {
        continue;
    }

    foreach (array_keys($impactCourseIds) as $cid) {
        $courseStats[$cid]['homeworks_total']++;
        if ($assignmentIsActive) {
            $courseStats[$cid]['active_homeworks']++;
        }
    }
    if ($assignmentIsActive) {
        $summaryActiveHomeworks++;
    }

    foreach ($assignees as $assigneeUsername => $state) {
        $uname = progress_normalize_username((string)$assigneeUsername);
        if ($uname === '') {
            continue;
        }
        if (!isset($studentStats[$uname])) {
            continue;
        }
        $belongs = $studentCourseMap[$uname] ?? [];
        if (!$belongs) {
            continue;
        }
        $submittedAt = trim((string)((is_array($state) ? ($state['submitted_at'] ?? '') : '')));
        $matchesFilter = $courseIdFilter === '' || isset($belongs[$courseIdFilter]);
        if (!$matchesFilter) {
            continue;
        }
        if ($assignmentIsActive && $submittedAt === '') {
            $studentStats[$uname]['pending_homeworks']++;
        }
        if ($createdTs > 0) {
            $trendIdx = $trendBucketIndexForTs($createdTs);
            if ($trendIdx !== null) {
                $trendBuckets[$trendIdx]['assigned']++;
            }
        }
        if ($createdInWindow) {
            $studentStats[$uname]['assigned_window']++;
            if ($submittedAt !== '') {
                $studentStats[$uname]['submitted_window']++;
                $submittedTs = progress_iso_ts($submittedAt);
                if ($submittedTs > 0) {
                    $trendSubmittedIdx = $trendBucketIndexForTs($submittedTs);
                    if ($trendSubmittedIdx !== null) {
                        $trendBuckets[$trendSubmittedIdx]['submitted']++;
                    }
                }
                if ($submittedTs > (int)$studentStats[$uname]['_last_submitted_ts']) {
                    $studentStats[$uname]['_last_submitted_ts'] = $submittedTs;
                    $studentStats[$uname]['last_submitted_at'] = $submittedAt;
                }
            }
            $summaryAssignedWindow++;
            if ($submittedAt !== '') {
                $summarySubmittedWindow++;
            }
        }

        $lastDtzResult = is_array($state['last_dtz_result'] ?? null) ? $state['last_dtz_result'] : null;
        if ($lastDtzResult) {
            $savedTs = progress_iso_ts((string)($lastDtzResult['saved_at'] ?? ''));
            if ($savedTs > 0 && $savedTs >= $windowStartTs && $savedTs <= $windowEndTs) {
                $correctVal = max(0, (int)($lastDtzResult['correct'] ?? 0));
                $wrongVal = max(0, (int)($lastDtzResult['wrong'] ?? 0));
                $totalVal = max(0, (int)($lastDtzResult['total'] ?? ($correctVal + $wrongVal + max(0, (int)($lastDtzResult['unanswered'] ?? 0)))));
                if ($totalVal < ($correctVal + $wrongVal)) {
                    $totalVal = $correctVal + $wrongVal;
                }
                $studentStats[$uname]['dtz_correct_window'] += $correctVal;
                $studentStats[$uname]['dtz_wrong_window'] += $wrongVal;
                $studentStats[$uname]['dtz_total_window'] += $totalVal;
            }
        }

        foreach (array_keys($belongs) as $cid) {
            if (!isset($courseStats[$cid])) {
                continue;
            }
            if ($createdInWindow) {
                $courseStats[$cid]['assigned_window']++;
                if ($submittedAt !== '') {
                    $courseStats[$cid]['submitted_window']++;
                }
            }
        }
    }
}

$storageDir = __DIR__ . '/storage';
$reminderRows = is_dir($storageDir) ? progress_read_jsonl_file($storageDir . '/homework_reminders_log.jsonl') : [];
foreach ($reminderRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $createdTs = progress_iso_ts((string)($row['created_at'] ?? ''));
    if ($createdTs <= 0 || $createdTs < $windowStartTs || $createdTs > $windowEndTs) {
        continue;
    }
    $assignmentId = trim((string)($row['assignment_id'] ?? ''));
    if ($assignmentId === '') {
        continue;
    }
    $studentUsername = progress_normalize_username((string)($row['student_username'] ?? ''));
    if ($studentUsername === '' || !isset($visibleStudents[$studentUsername])) {
        continue;
    }
    if ($studentUsernameFilter !== '' && $studentUsername !== $studentUsernameFilter) {
        continue;
    }
    $assignment = is_array($visibleAssignmentsById[$assignmentId] ?? null) ? $visibleAssignmentsById[$assignmentId] : null;
    if (!$assignment) {
        continue;
    }
    $courseId = trim((string)($assignment['course_id'] ?? ''));
    if ($courseIdFilter !== '' && $courseId !== $courseIdFilter) {
        continue;
    }
    $templateId = trim((string)($assignment['template_id'] ?? ''));
    $summaryRemindersWindow++;
    $courseKey = $courseId !== '' ? $courseId : '-';
    $templateKey = $templateId !== '' ? $templateId : '-';
    $level = strtolower(trim((string)($row['level'] ?? '')));
    if (!isset($remindersByLevel[$level])) {
        $level = 'other';
    }
    $remindersByLevel[$level] = (int)($remindersByLevel[$level] ?? 0) + 1;
    $remindersByCourse[$courseKey] = (int)($remindersByCourse[$courseKey] ?? 0) + 1;
    $remindersByTemplate[$templateKey] = (int)($remindersByTemplate[$templateKey] ?? 0) + 1;
    if ($courseId !== '' && isset($courseStats[$courseId])) {
        $courseStats[$courseId]['reminders_sent_window'] = (int)($courseStats[$courseId]['reminders_sent_window'] ?? 0) + 1;
    }
}
arsort($remindersByCourse, SORT_NUMERIC);
arsort($remindersByTemplate, SORT_NUMERIC);
$remindersByCourseRows = [];
foreach (array_slice($remindersByCourse, 0, 20, true) as $courseId => $count) {
    $label = $courseId;
    if ($courseId !== '-' && isset($visibleCourses[$courseId])) {
        $label = (string)($visibleCourses[$courseId]['name'] ?? $courseId);
    }
    $remindersByCourseRows[] = [
        'course_id' => $courseId,
        'label' => $label,
        'count' => (int)$count,
    ];
}
$remindersByTemplateRows = [];
foreach (array_slice($remindersByTemplate, 0, 20, true) as $templateId => $count) {
    $remindersByTemplateRows[] = [
        'template_id' => $templateId,
        'count' => (int)$count,
    ];
}

$reviewsByUpload = is_dir($storageDir) ? load_letter_reviews_index($storageDir) : [];
$letterRows = is_dir($storageDir) ? progress_read_letters($storageDir) : [];

$summaryPendingReviews = 0;
$summaryApprovedWindow = 0;
$summaryScoreSumWindow = 0;
$summaryScoreCountWindow = 0;
$recentApproved = [];

foreach ($letterRows as $letter) {
    if (!is_array($letter)) {
        continue;
    }

    $studentUsername = progress_normalize_username((string)($letter['student_username'] ?? ''));
    $teacherUsername = progress_normalize_username((string)($letter['teacher_username'] ?? ''));
    $uploadId = trim((string)($letter['upload_id'] ?? ''));
    if ($uploadId === '') {
        continue;
    }

    $allowed = true;
    if (!admin_is_hauptadmin($admin)) {
        $allowed = isset($visibleStudents[$studentUsername]);
        if (!$allowed) {
            $adminUsername = progress_normalize_username((string)($admin['username'] ?? ''));
            if ($adminUsername !== '' && $teacherUsername !== '' && hash_equals($adminUsername, $teacherUsername)) {
                $allowed = true;
            }
        }
    }
    if (!$allowed) {
        continue;
    }

    $courseIds = array_keys($studentCourseMap[$studentUsername] ?? []);
    if ($courseIdFilter !== '') {
        if (!in_array($courseIdFilter, $courseIds, true)) {
            continue;
        }
        $courseIds = [$courseIdFilter];
    }

    $review = is_array($reviewsByUpload[$uploadId] ?? null) ? $reviewsByUpload[$uploadId] : null;
    $decision = strtolower((string)($review['decision'] ?? ''));

    if ($decision !== 'approve' && $decision !== 'reject') {
        $summaryPendingReviews++;
        foreach ($courseIds as $cid) {
            if (isset($courseStats[$cid])) {
                $courseStats[$cid]['pending_reviews']++;
            }
        }
        continue;
    }

    if ($decision === 'approve') {
        $reviewedAt = (string)($review['reviewed_at'] ?? '');
        $reviewedTs = progress_iso_ts($reviewedAt);
        $score = progress_extract_score($review);

        if ($reviewedTs >= $windowStartTs && $reviewedTs <= $windowEndTs && $score !== null) {
            $summaryApprovedWindow++;
            $summaryScoreSumWindow += $score;
            $summaryScoreCountWindow++;
            if (isset($studentStats[$studentUsername])) {
                $studentStats[$studentUsername]['_score_sum_window'] += $score;
                $studentStats[$studentUsername]['_score_count_window']++;
            }
            $trendScoreIdx = $trendBucketIndexForTs($reviewedTs);
            if ($trendScoreIdx !== null) {
                $trendBuckets[$trendScoreIdx]['_score_sum'] += $score;
                $trendBuckets[$trendScoreIdx]['_score_count']++;
            }
            foreach ($courseIds as $cid) {
                if (!isset($courseStats[$cid])) {
                    continue;
                }
                $courseStats[$cid]['_score_sum_window'] += $score;
                $courseStats[$cid]['_score_count_window']++;
            }
        }

        $recentApproved[] = [
            'upload_id' => $uploadId,
            'student_username' => (string)($letter['student_username'] ?? ''),
            'student_name' => (string)($letter['student_name'] ?? ''),
            'course_id' => $courseIds[0] ?? '',
            'score_total' => $score,
            'reviewed_at' => $reviewedAt,
            'task_title' => (string)($letter['task_title'] ?? ''),
            'task_prompt' => (string)($letter['task_prompt'] ?? ''),
        ];
    }
}

$courseRows = [];
foreach ($courseStats as $cid => $row) {
    $assigned = (int)$row['assigned_window'];
    $submitted = (int)$row['submitted_window'];
    $completion = $assigned > 0 ? (int)round(($submitted / $assigned) * 100) : 0;

    $scoreCount = (int)$row['_score_count_window'];
    $avgScoreWindow = $scoreCount > 0 ? round($row['_score_sum_window'] / $scoreCount, 1) : null;

    unset($row['_score_sum_window'], $row['_score_count_window']);
    $row['completion_window_percent'] = $completion;
    $row['avg_score_window'] = $avgScoreWindow;
    $courseRows[] = $row;
}

usort($courseRows, static function (array $a, array $b): int {
    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

$studentRows = [];
foreach ($studentStats as $uname => $row) {
    $assigned = (int)$row['assigned_window'];
    $submitted = (int)$row['submitted_window'];
    $completion = $assigned > 0 ? (int)round(($submitted / $assigned) * 100) : 0;
    $scoreCount = (int)$row['_score_count_window'];
    $avgScoreWindow = $scoreCount > 0 ? round($row['_score_sum_window'] / $scoreCount, 1) : null;
    unset($row['_score_sum_window'], $row['_score_count_window'], $row['_last_submitted_ts']);
    $row['completion_window_percent'] = $completion;
    $row['avg_score_window'] = $avgScoreWindow;
    $dtzTotal = (int)($row['dtz_total_window'] ?? 0);
    $dtzCorrect = (int)($row['dtz_correct_window'] ?? 0);
    $row['dtz_accuracy_window'] = $dtzTotal > 0 ? (int)round(($dtzCorrect / $dtzTotal) * 100) : null;
    $studentRows[] = $row;
}

usort($studentRows, static function (array $a, array $b): int {
    $pendingA = (int)($a['pending_homeworks'] ?? 0);
    $pendingB = (int)($b['pending_homeworks'] ?? 0);
    if ($pendingA !== $pendingB) {
        return $pendingB <=> $pendingA;
    }
    $completionA = (int)($a['completion_window_percent'] ?? 0);
    $completionB = (int)($b['completion_window_percent'] ?? 0);
    if ($completionA !== $completionB) {
        return $completionA <=> $completionB;
    }
    return strcmp((string)($a['student_username'] ?? ''), (string)($b['student_username'] ?? ''));
});
$studentRows = array_slice($studentRows, 0, 200);

$riskRows = [];
foreach ($studentRows as $row) {
    $assigned = (int)($row['assigned_window'] ?? 0);
    if ($assigned <= 0) {
        continue;
    }
    $completion = (int)($row['completion_window_percent'] ?? 0);
    if ($completion >= 50) {
        continue;
    }
    $riskRows[] = [
        'student_username' => (string)($row['student_username'] ?? ''),
        'student_name' => (string)($row['student_name'] ?? ''),
        'assigned_window' => $assigned,
        'submitted_window' => (int)($row['submitted_window'] ?? 0),
        'completion_window_percent' => $completion,
        'pending_homeworks' => (int)($row['pending_homeworks'] ?? 0),
        'last_submitted_at' => (string)($row['last_submitted_at'] ?? ''),
    ];
}
usort($riskRows, static function (array $a, array $b): int {
    if ($a['completion_window_percent'] !== $b['completion_window_percent']) {
        return $a['completion_window_percent'] <=> $b['completion_window_percent'];
    }
    if ($a['pending_homeworks'] !== $b['pending_homeworks']) {
        return $b['pending_homeworks'] <=> $a['pending_homeworks'];
    }
    return strcmp((string)$a['student_username'], (string)$b['student_username']);
});
$riskRows = array_slice($riskRows, 0, 50);

$trendRows = [];
foreach ($trendBuckets as $bucket) {
    $scoreCount = (int)$bucket['_score_count'];
    $avgScore = $scoreCount > 0 ? round((float)$bucket['_score_sum'] / $scoreCount, 1) : null;
    $assigned = (int)$bucket['assigned'];
    $submitted = (int)$bucket['submitted'];
    $completion = $assigned > 0 ? (int)round(($submitted / $assigned) * 100) : 0;
    $trendRows[] = [
        'label' => (string)$bucket['label'],
        'start_date' => gmdate('Y-m-d', (int)$bucket['start_ts']),
        'end_date' => gmdate('Y-m-d', (int)$bucket['end_ts']),
        'assigned' => $assigned,
        'submitted' => $submitted,
        'completion_percent' => $completion,
        'avg_score' => $avgScore,
    ];
}

usort($recentApproved, static function (array $a, array $b): int {
    return strcmp((string)($b['reviewed_at'] ?? ''), (string)($a['reviewed_at'] ?? ''));
});
$recentApproved = array_slice($recentApproved, 0, 20);

usort($recentModelltestResults, static function (array $a, array $b): int {
    return strcmp((string)($b['saved_at'] ?? ''), (string)($a['saved_at'] ?? ''));
});
$recentModelltestResults = array_slice($recentModelltestResults, 0, 30);

$summary = [
    'window_days' => $windowDays,
    'courses_total' => count($courseRows),
    'students_total' => count($visibleStudents),
    'active_homeworks_total' => $summaryActiveHomeworks,
    'pending_reviews_total' => $summaryPendingReviews,
    'risk_students_total' => count($riskRows),
    'assigned_window_total' => $summaryAssignedWindow,
    'submitted_window_total' => $summarySubmittedWindow,
    'completion_window_percent' => $summaryAssignedWindow > 0 ? (int)round(($summarySubmittedWindow / $summaryAssignedWindow) * 100) : 0,
    'approved_reviews_window_total' => $summaryApprovedWindow,
    'reminders_sent_window_total' => $summaryRemindersWindow,
    'avg_score_window' => $summaryScoreCountWindow > 0 ? round($summaryScoreSumWindow / $summaryScoreCountWindow, 1) : null,
    'range_from' => $rangeFromRaw,
    'range_to' => $rangeToRaw,
    'window_end' => gmdate('c', $windowEndTs),
];

echo json_encode([
    'summary' => $summary,
    'courses' => $courseRows,
    'students' => $studentRows,
    'risk_students' => $riskRows,
    'trend_weeks' => $trendRows,
    'selected_course' => $courseIdFilter !== '' && isset($visibleCourses[$courseIdFilter]) ? [
        'course_id' => $courseIdFilter,
        'name' => (string)($visibleCourses[$courseIdFilter]['name'] ?? $courseIdFilter),
    ] : null,
    'selected_student' => $studentUsernameFilter !== '' && isset($visibleStudents[$studentUsernameFilter]) ? [
        'student_username' => (string)($visibleStudents[$studentUsernameFilter]['username'] ?? $studentUsernameFilter),
        'student_name' => (string)($visibleStudents[$studentUsernameFilter]['display_name'] ?? $visibleStudents[$studentUsernameFilter]['name'] ?? ''),
    ] : null,
    'recent_approved' => $recentApproved,
    'recent_modelltest_results' => $recentModelltestResults,
    'reminders_by_course' => $remindersByCourseRows,
    'reminders_by_template' => $remindersByTemplateRows,
    'reminders_by_level' => $remindersByLevel,
], JSON_UNESCAPED_UNICODE);
