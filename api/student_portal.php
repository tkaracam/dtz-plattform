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

function detect_student_homework_category(array $assignment): string
{
    $templateId = mb_strtolower(trim((string)($assignment['template_id'] ?? '')));
    if (preg_match('/^dtz-hoeren-teil[1-4]-fragenpaket$/', $templateId) === 1) {
        return 'hoeren';
    }
    if (preg_match('/^dtz-lesen-teil[1-5]-fragenpaket$/', $templateId) === 1) {
        return 'lesen';
    }
    if (
        $templateId === 'dtz-mock-pruefung-komplett'
        || $templateId === 'dtz-mock-prüfung-komplett'
        || strpos($templateId, 'modelltest') !== false
        || strpos($templateId, 'mock-pruefung') !== false
        || strpos($templateId, 'mock-prüfung') !== false
    ) {
        return 'modelltest';
    }
    if (
        strpos($templateId, 'a2-thema-') === 0
        || strpos($templateId, 'a2-test-') === 0
        || strpos($templateId, 'a2-test-generator-') === 0
    ) {
        return 'a2';
    }

    $title = mb_strtolower(trim((string)($assignment['title'] ?? '')));
    $description = mb_strtolower(trim((string)($assignment['description'] ?? '')));
    $attachment = mb_strtolower(trim((string)($assignment['attachment'] ?? '')));
    $bag = trim($title . ' ' . $description . ' ' . $attachment);

    if (preg_match('/^(mail|mail-aufgabe)\b/u', $title) === 1) return 'mail';
    if (preg_match('/^(modelltest|dtz modelltest|dtz mock-pr[üu]fung|mock-pr[üu]fung)\b/u', $title) === 1) return 'modelltest';
    if (preg_match('/^h[öo]ren\b/u', $title) === 1 || preg_match('/h[öo]ren\s*teil\s*[1-4]/u', $title) === 1) return 'hoeren';
    if (preg_match('/^lesen\b/u', $title) === 1 || preg_match('/lesen\s*teil\s*[1-5]/u', $title) === 1) return 'lesen';
    if (preg_match('/^a2\b|^a2-/u', $title) === 1 || strpos($title, 'a2-grammatik') !== false) return 'a2';

    if (strpos($bag, 'a2') !== false) return 'a2';
    if (strpos($bag, 'modelltest') !== false || strpos($bag, 'mock-prüfung') !== false || strpos($bag, 'mock-pruefung') !== false) return 'modelltest';
    if (strpos($bag, 'hören') !== false || strpos($bag, 'hoeren') !== false) return 'hoeren';
    if (strpos($bag, 'lesen') !== false) return 'lesen';
    if (strpos($bag, 'schreiben') !== false || strpos($bag, 'mail') !== false) return 'mail';
    return 'unknown';
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
    $assignmentStatus = mb_strtolower(trim((string)($assignment['status'] ?? 'active')));
    $startsAt = trim((string)($assignment['starts_at'] ?? ''));
    $startsAtTs = $startsAt !== '' ? strtotime($startsAt) : false;
    $plannedInFuture = ($startsAtTs !== false && (int)$startsAtTs > $nowTs);

    $status = 'active';
    $statusLabel = 'offen';
    if ($submittedAt !== '') {
        $status = 'submitted';
        $statusLabel = 'abgegeben';
    } elseif ($assignmentStatus !== 'active') {
        $status = 'archived';
        $statusLabel = 'archiviert';
    } elseif ($expired) {
        $status = 'expired';
        $statusLabel = 'abgelaufen';
    } elseif ($startedAt !== '') {
        $status = 'active';
        $statusLabel = 'läuft';
    } elseif ($plannedInFuture) {
        $status = 'planned';
        $statusLabel = 'geplant';
    }

    $homeworks[] = [
        'id' => (string)($assignment['id'] ?? ''),
        'template_id' => (string)($assignment['template_id'] ?? ''),
        'title' => (string)($assignment['title'] ?? 'Aufgabe'),
        'description' => (string)($assignment['description'] ?? ''),
        'attachment' => (string)($assignment['attachment'] ?? ''),
        'category' => detect_student_homework_category($assignment),
        'duration_minutes' => (int)($assignment['duration_minutes'] ?? 0),
        'due_date' => $deadlineAt !== '' ? $deadlineAt : (string)($assignment['starts_at'] ?? ''),
        'status' => $status,
        'status_label' => $statusLabel,
        'reminder_level' => (string)($reminder['level'] ?? 'none'),
        'reminder_label' => (string)($reminder['label'] ?? 'Keine Fristwarnung'),
        'reminder_urgent' => !empty($reminder['urgent']),
        'remaining_seconds' => (int)($reminder['remaining_seconds'] ?? 0),
    ];
}
usort($homeworks, static function (array $a, array $b): int {
    $statusRank = static function (string $status): int {
        $key = mb_strtolower(trim($status));
        if ($key === 'active') return 5;
        if ($key === 'planned') return 4;
        if ($key === 'expired') return 2;
        if ($key === 'submitted') return 1;
        if ($key === 'archived') return 0;
        return 0;
    };

    $aRank = $statusRank((string)($a['status'] ?? ''));
    $bRank = $statusRank((string)($b['status'] ?? ''));
    if ($aRank !== $bRank) {
        return $bRank <=> $aRank;
    }

    $aDue = (string)($a['due_date'] ?? '');
    $bDue = (string)($b['due_date'] ?? '');
    return strcmp($bDue, $aDue);
});
$homeworks = array_slice($homeworks, 0, 200);

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
