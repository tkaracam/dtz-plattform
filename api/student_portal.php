<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
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
require_once __DIR__ . '/letter_reviews.php';
require_once __DIR__ . '/homework_lib.php';
$student = require_student_session_json();
$username = mb_strtolower(trim((string)($student['username'] ?? '')));

$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir)) {
    echo json_encode([
        'results' => [],
        'homeworks' => [],
        'teacher_notes' => [],
        'readiness' => ['dtz' => 0, 'dtb' => 0, 'missing_topics' => []],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_file_array(string $filePath): array
{
    if (!is_file($filePath)) {
        return [];
    }
    $raw = file_get_contents($filePath);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function read_jsonl_records(string $pattern): array
{
    $files = glob($pattern) ?: [];
    rsort($files, SORT_STRING);
    $out = [];
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
            $item = json_decode($line, true);
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        fclose($handle);
    }
    return $out;
}

$simRecords = [];
$letterRecords = [];
$approvedLetterResults = [];
$approvedLetterCorrections = [];
$latestLetterCorrection = null;
$latestLetterCorrectionTs = 0;

$reviewsByUpload = load_letter_reviews_index($storageDir);

foreach (read_jsonl_records($storageDir . '/simulations-*.jsonl') as $row) {
    $u = mb_strtolower(trim((string)($row['student_username'] ?? '')));
    if ($u !== $username) {
        continue;
    }
    $simRecords[] = [
        'type' => 'Simulation',
        'created_at' => (string)($row['created_at'] ?? ''),
        'score_label' => (int)($row['score_total'] ?? 0) . '/20',
        'percent' => (int)round(((int)($row['score_total'] ?? 0) / 20) * 100),
        'detail' => ((int)($row['duration_minutes'] ?? 0)) . ' Min',
        'missing_topics' => is_array($row['missing_topics'] ?? null) ? $row['missing_topics'] : [],
    ];
}

foreach (read_jsonl_records($storageDir . '/letters-*.jsonl') as $row) {
    $u = mb_strtolower(trim((string)($row['student_username'] ?? '')));
    if ($u !== $username) {
        continue;
    }
    $uploadId = trim((string)($row['upload_id'] ?? ''));
    $review = is_array($reviewsByUpload[$uploadId] ?? null) ? $reviewsByUpload[$uploadId] : null;
    $decision = strtolower((string)($review['decision'] ?? ''));
    if ($decision === 'approve') {
        $result = is_array($review['correction_result'] ?? null) ? $review['correction_result'] : null;
        $score = is_array($result) ? (int)($result['score_total'] ?? 0) : 0;
        $approvedLetterResults[] = [
            'type' => 'Brief-Korrektur freigegeben',
            'created_at' => (string)($review['reviewed_at'] ?? $row['created_at'] ?? ''),
            'score_label' => $score . '/20',
            'percent' => (int)round(($score / 20) * 100),
            'detail' => mb_substr(trim((string)($row['task_prompt'] ?? 'Ohne Aufgabenangabe')), 0, 80),
        ];
        if (is_array($result)) {
            $recordWithMeta = array_merge($result, [
                'created_at' => (string)($review['reviewed_at'] ?? $row['created_at'] ?? ''),
                'topic' => trim((string)($row['task_prompt'] ?? '')),
                'upload_id' => $uploadId,
            ]);
            $approvedLetterCorrections[] = $recordWithMeta;
        }
        $reviewTs = strtotime((string)($review['reviewed_at'] ?? '')) ?: 0;
        if (!empty($recordWithMeta) && $reviewTs >= $latestLetterCorrectionTs) {
            $latestLetterCorrection = $recordWithMeta;
            $latestLetterCorrectionTs = $reviewTs;
        }
        continue;
    }

    if ($decision === 'reject') {
        $letterRecords[] = [
            'type' => 'Brief abgelehnt',
            'created_at' => (string)($review['reviewed_at'] ?? $row['created_at'] ?? ''),
            'score_label' => '-',
            'percent' => 0,
            'detail' => 'Bitte überarbeiten und erneut senden.',
        ];
        continue;
    }

    $letterRecords[] = [
        'type' => 'Brief eingereicht',
        'created_at' => (string)($row['created_at'] ?? ''),
        'score_label' => '-',
        'percent' => 0,
        'detail' => 'Wartet auf Freigabe durch Lehrkraft.',
    ];
}

$allResults = array_merge($simRecords, $approvedLetterResults, $letterRecords);
usort($allResults, static function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});
$allResults = array_slice($allResults, 0, 20);

usort($approvedLetterCorrections, static function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

$homeworks = [];
$nowTs = time();
foreach (load_homework_assignments() as $assignment) {
    if (!is_array($assignment)) {
        continue;
    }
    if (!assignment_targets_student($assignment, $username)) {
        continue;
    }

    $state = assignment_user_state($assignment, $username);
    $submittedAt = trim((string)($state['submitted_at'] ?? ''));
    $startedAt = trim((string)($state['started_at'] ?? ''));
    $deadlineAt = trim((string)($state['deadline_at'] ?? ''));
    $deadlineTs = (int)($state['deadline_ts'] ?? 0);
    $expired = ($submittedAt === '' && $deadlineTs > 0 && $nowTs >= $deadlineTs);
    $reminder = homework_reminder_for_state($state, $nowTs);

    $status = 'offen';
    if ($submittedAt !== '') {
        $status = 'abgegeben';
    } elseif ($expired) {
        $status = 'abgelaufen';
    } elseif ($startedAt !== '') {
        $status = 'läuft';
    } elseif (!assignment_is_active_now($assignment, $nowTs)) {
        $status = 'geplant';
    }

    $homeworks[] = [
        'id' => (string)($assignment['id'] ?? ''),
        'template_id' => (string)($assignment['template_id'] ?? ''),
        'title' => (string)($assignment['title'] ?? 'Aufgabe'),
        'description' => (string)($assignment['description'] ?? ''),
        'attachment' => (string)($assignment['attachment'] ?? ''),
        'duration_minutes' => (int)($assignment['duration_minutes'] ?? 0),
        'due_date' => $deadlineAt !== '' ? $deadlineAt : (string)($assignment['starts_at'] ?? ''),
        'status' => $status,
        'reminder_level' => (string)($reminder['level'] ?? 'none'),
        'reminder_label' => (string)($reminder['label'] ?? 'Keine Fristwarnung'),
        'reminder_urgent' => !empty($reminder['urgent']),
        'remaining_seconds' => (int)($reminder['remaining_seconds'] ?? 0),
    ];
}
usort($homeworks, static function (array $a, array $b): int {
    return strcmp((string)($b['due_date'] ?? ''), (string)($a['due_date'] ?? ''));
});
$homeworks = array_slice($homeworks, 0, 30);

$notesRaw = read_json_file_array($storageDir . '/teacher_notes.json');
$teacherNotes = [];
foreach ($notesRaw as $row) {
    if (!is_array($row)) {
        continue;
    }
    $target = mb_strtolower(trim((string)($row['student_username'] ?? '')));
    if ($target !== '' && $target !== $username && $target !== '*') {
        continue;
    }
    $teacherNotes[] = [
        'id' => (string)($row['id'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'note' => (string)($row['note'] ?? ''),
        'teacher' => (string)($row['teacher'] ?? 'Lehrkraft'),
    ];
}
usort($teacherNotes, static function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

$simPercents = array_map(static fn($r) => (int)($r['percent'] ?? 0), $simRecords);
$avgSim = count($simPercents) ? (int)round(array_sum($simPercents) / count($simPercents)) : 0;

$dtzReadiness = $avgSim;
$dtbReadiness = $avgSim;

$missingMap = [];
foreach ($simRecords as $r) {
    foreach ((array)($r['missing_topics'] ?? []) as $topic) {
        $label = trim((string)$topic);
        if ($label === '') {
            continue;
        }
        $missingMap[$label] = ($missingMap[$label] ?? 0) + 1;
    }
}

if ($avgSim < 65) {
    $missingMap['Textaufbau und Struktur'] = ($missingMap['Textaufbau und Struktur'] ?? 0) + 2;
    $missingMap['Grammatik im Satzbau'] = ($missingMap['Grammatik im Satzbau'] ?? 0) + 2;
}
$rankedMissing = [];
arsort($missingMap, SORT_NUMERIC);
foreach ($missingMap as $k => $v) {
    $rankedMissing[] = $k;
}
$rankedMissing = array_slice($rankedMissing, 0, 8);

echo json_encode([
    'results' => $allResults,
    'homeworks' => $homeworks,
    'teacher_notes' => array_slice($teacherNotes, 0, 20),
    'latest_letter_correction' => $latestLetterCorrection,
    'letter_corrections' => $approvedLetterCorrections,
    'readiness' => [
        'dtz' => max(0, min(100, $dtzReadiness)),
        'dtb' => max(0, min(100, $dtbReadiness)),
        'missing_topics' => $rankedMissing,
    ],
], JSON_UNESCAPED_UNICODE);
