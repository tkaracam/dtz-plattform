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
$admin = require_admin_session_json();
require_once __DIR__ . '/letter_reviews.php';

function format_writing_duration_label(int $seconds): string
{
    $safe = max(0, min(14400, $seconds));
    if ($safe <= 0) {
        return '-';
    }
    if ($safe < 60) {
        return $safe . ' Sek';
    }
    $minutes = (int)floor($safe / 60);
    $restSeconds = $safe % 60;
    if ($minutes < 60) {
        return $restSeconds > 0 ? ($minutes . ' Min ' . $restSeconds . ' Sek') : ($minutes . ' Min');
    }
    $hours = (int)floor($minutes / 60);
    $restMinutes = $minutes % 60;
    if ($restMinutes > 0) {
        return $hours . 'h ' . $restMinutes . 'm';
    }
    return $hours . 'h';
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = 100;
if (isset($body['limit']) && is_numeric($body['limit'])) {
    $limit = (int)$body['limit'];
}
$limit = max(1, min(200, $limit));

$dateFrom = trim((string)($body['date_from'] ?? ''));
$dateTo = trim((string)($body['date_to'] ?? ''));
$studentQuery = mb_strtolower(trim((string)($body['student_query'] ?? '')));
$textQuery = mb_strtolower(trim((string)($body['text_query'] ?? '')));
$courseIdFilter = trim((string)($body['course_id'] ?? ''));

$tsFrom = null;
$tsTo = null;
if ($dateFrom !== '') {
    $tmp = strtotime($dateFrom . ' 00:00:00');
    if ($tmp !== false) {
        $tsFrom = $tmp;
    }
}
if ($dateTo !== '') {
    $tmp = strtotime($dateTo . ' 23:59:59');
    if ($tmp !== false) {
        $tsTo = $tmp;
    }
}

$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir)) {
    echo json_encode(['records' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$reviewsByUpload = load_letter_reviews_index($storageDir);

$allowedUsernames = [];
if (($admin['role'] ?? '') === 'docent') {
    foreach (load_student_users() as $student) {
        if (!is_array($student)) {
            continue;
        }
        if (!admin_can_access_student_record($student, $admin)) {
            continue;
        }
        $uname = mb_strtolower(trim((string)($student['username'] ?? '')));
        if ($uname !== '') {
            $allowedUsernames[$uname] = true;
        }
    }
}

$courseUsernameFilter = null;
if ($courseIdFilter !== '') {
    $courses = load_courses();
    $targetCourse = null;
    foreach ($courses as $course) {
        if (!is_array($course)) {
            continue;
        }
        if ((string)($course['course_id'] ?? '') !== $courseIdFilter) {
            continue;
        }
        $targetCourse = $course;
        break;
    }
    if (!is_array($targetCourse)) {
        http_response_code(404);
        echo json_encode(['error' => 'Kurs wurde nicht gefunden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!admin_can_access_course_record($targetCourse, $admin)) {
        http_response_code(403);
        echo json_encode(['error' => 'Kein Zugriff auf diesen Kurs.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $courseUsernameFilter = [];
    foreach ((array)($targetCourse['members'] ?? []) as $memberUsername) {
        $uname = mb_strtolower(trim((string)$memberUsername));
        if ($uname !== '') {
            $courseUsernameFilter[$uname] = true;
        }
    }
}

$files = glob($storageDir . '/letters-*.jsonl') ?: [];
rsort($files, SORT_STRING);

$records = [];

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

        $record = json_decode($line, true);
        if (!is_array($record)) {
            continue;
        }

        $createdAt = (string)($record['created_at'] ?? '');
        $ts = strtotime($createdAt);
        if ($ts === false) {
            $ts = 0;
        }

        if ($tsFrom !== null && $ts < $tsFrom) {
            continue;
        }
        if ($tsTo !== null && $ts > $tsTo) {
            continue;
        }

        $studentName = (string)($record['student_name'] ?? '');
        $studentUsername = mb_strtolower(trim((string)($record['student_username'] ?? '')));
        $recordTeacher = mb_strtolower(trim((string)($record['teacher_username'] ?? '')));
        $taskPrompt = (string)($record['task_prompt'] ?? '');
        $letterText = (string)($record['letter_text'] ?? '');
        $writingDurationSeconds = (int)($record['writing_duration_seconds'] ?? 0);
        $writingStartedAt = (string)($record['writing_started_at'] ?? '');

        if (($admin['role'] ?? '') === 'docent') {
            $allowed = !empty($allowedUsernames[$studentUsername]);
            if (!$allowed) {
                $adminUsername = mb_strtolower(trim((string)($admin['username'] ?? '')));
                if ($adminUsername !== '' && $recordTeacher !== '' && hash_equals($adminUsername, $recordTeacher)) {
                    $allowed = true;
                }
            }
            if (!$allowed) {
                continue;
            }
        }

        if (is_array($courseUsernameFilter) && empty($courseUsernameFilter[$studentUsername])) {
            continue;
        }

        if ($studentQuery !== '' && !str_contains(mb_strtolower($studentName), $studentQuery)) {
            continue;
        }

        if ($textQuery !== '') {
            $haystack = mb_strtolower($taskPrompt . "\n" . $letterText);
            if (!str_contains($haystack, $textQuery)) {
                continue;
            }
        }

        $records[] = [
            'upload_id' => (string)($record['upload_id'] ?? ''),
            'created_at' => $createdAt,
            'student_name' => $studentName,
            'student_username' => (string)($record['student_username'] ?? ''),
            'task_prompt' => $taskPrompt,
            'required_points' => is_array($record['required_points'] ?? null) ? $record['required_points'] : [],
            'letter_text' => $letterText,
            'writing_duration_seconds' => $writingDurationSeconds,
            'writing_started_at' => $writingStartedAt,
            'writing_duration_label' => format_writing_duration_label($writingDurationSeconds),
            'review_status' => 'pending',
            'reviewed_at' => '',
            'review_decision' => '',
            'score_total' => null,
        ];

    }

    fclose($handle);
}

foreach ($records as &$row) {
    $uploadId = (string)($row['upload_id'] ?? '');
    $review = is_array($reviewsByUpload[$uploadId] ?? null) ? $reviewsByUpload[$uploadId] : null;
    if (!$review) {
        continue;
    }
    $decision = strtolower((string)($review['decision'] ?? ''));
    $row['review_status'] = $decision === 'approve' ? 'freigegeben' : ($decision === 'reject' ? 'abgelehnt' : 'pending');
    $row['review_decision'] = $decision;
    $row['reviewed_at'] = (string)($review['reviewed_at'] ?? '');
    $result = is_array($review['correction_result'] ?? null) ? $review['correction_result'] : null;
    if ($result) {
        $row['score_total'] = isset($result['score_total']) ? (int)$result['score_total'] : null;
    }
}
unset($row);

usort($records, static function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

if (count($records) > $limit) {
    $records = array_slice($records, 0, $limit);
}

echo json_encode(['records' => $records], JSON_UNESCAPED_UNICODE);
