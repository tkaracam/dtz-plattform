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
    echo json_encode(['error' => 'Nur GET wird unterstuetzt.'], JSON_UNESCAPED_UNICODE);
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

$courseIdFilter = trim((string)($_GET['course_id'] ?? ''));
$windowDays = (int)($_GET['days'] ?? 7);
if ($windowDays < 1 || $windowDays > 90) {
    $windowDays = 7;
}
$nowTs = time();
$windowStartTs = $nowTs - ($windowDays * 86400);

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

$studentStats = [];
foreach ($visibleStudents as $uname => $student) {
    $studentName = trim((string)($student['display_name'] ?? $student['name'] ?? ''));
    $studentStats[$uname] = [
        'student_username' => (string)($student['username'] ?? $uname),
        'student_name' => $studentName,
        'assigned_window' => 0,
        'submitted_window' => 0,
        'pending_homeworks' => 0,
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
        'avg_score_window' => null,
        '_score_sum_window' => 0,
        '_score_count_window' => 0,
    ];
}

$visibleAssignments = [];
foreach (load_homework_assignments() as $assignment) {
    if (!is_array($assignment)) {
        continue;
    }
    if (!assignment_visibility_for_admin($assignment, $admin)) {
        continue;
    }
    $visibleAssignments[] = $assignment;
}

$summaryAssignedWindow = 0;
$summarySubmittedWindow = 0;
$summaryActiveHomeworks = 0;

foreach ($visibleAssignments as $assignment) {
    $assignees = is_array($assignment['assignees'] ?? null) ? $assignment['assignees'] : [];
    $assignmentCourseId = trim((string)($assignment['course_id'] ?? ''));
    $createdTs = progress_iso_ts((string)($assignment['created_at'] ?? ''));
    $createdInWindow = $createdTs > 0 && $createdTs >= $windowStartTs;
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
        if ($createdInWindow) {
            $studentStats[$uname]['assigned_window']++;
            if ($submittedAt !== '') {
                $studentStats[$uname]['submitted_window']++;
                $submittedTs = progress_iso_ts($submittedAt);
                if ($submittedTs > (int)$studentStats[$uname]['_last_submitted_ts']) {
                    $studentStats[$uname]['_last_submitted_ts'] = $submittedTs;
                    $studentStats[$uname]['last_submitted_at'] = $submittedAt;
                }
            }
        }
        foreach (array_keys($belongs) as $cid) {
            if (!isset($courseStats[$cid])) {
                continue;
            }
            if ($createdInWindow) {
                $courseStats[$cid]['assigned_window']++;
                $summaryAssignedWindow++;
                if ($submittedAt !== '') {
                    $courseStats[$cid]['submitted_window']++;
                    $summarySubmittedWindow++;
                }
            }
        }
    }
}

$storageDir = __DIR__ . '/storage';
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

        if ($reviewedTs >= $windowStartTs && $score !== null) {
            $summaryApprovedWindow++;
            $summaryScoreSumWindow += $score;
            $summaryScoreCountWindow++;
            if (isset($studentStats[$studentUsername])) {
                $studentStats[$studentUsername]['_score_sum_window'] += $score;
                $studentStats[$studentUsername]['_score_count_window']++;
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

usort($recentApproved, static function (array $a, array $b): int {
    return strcmp((string)($b['reviewed_at'] ?? ''), (string)($a['reviewed_at'] ?? ''));
});
$recentApproved = array_slice($recentApproved, 0, 20);

$summary = [
    'window_days' => $windowDays,
    'courses_total' => count($courseRows),
    'students_total' => count($visibleStudents),
    'active_homeworks_total' => $summaryActiveHomeworks,
    'pending_reviews_total' => $summaryPendingReviews,
    'assigned_window_total' => $summaryAssignedWindow,
    'submitted_window_total' => $summarySubmittedWindow,
    'completion_window_percent' => $summaryAssignedWindow > 0 ? (int)round(($summarySubmittedWindow / $summaryAssignedWindow) * 100) : 0,
    'approved_reviews_window_total' => $summaryApprovedWindow,
    'avg_score_window' => $summaryScoreCountWindow > 0 ? round($summaryScoreSumWindow / $summaryScoreCountWindow, 1) : null,
];

echo json_encode([
    'summary' => $summary,
    'courses' => $courseRows,
    'students' => $studentRows,
    'recent_approved' => $recentApproved,
], JSON_UNESCAPED_UNICODE);
