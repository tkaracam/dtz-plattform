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
require_once __DIR__ . '/letter_reviews.php';
require_once __DIR__ . '/correction_engine.php';

function read_json_array_file(string $file): array
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

function write_json_array_file(string $file, array $rows): bool
{
    $json = json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return @file_put_contents($file, $json . PHP_EOL, LOCK_EX) !== false;
}

function append_teacher_note(string $storageDir, string $studentUsername, string $noteText): void
{
    if ($studentUsername === '') {
        return;
    }
    $file = $storageDir . '/teacher_notes.json';
    $rows = read_json_array_file($file);
    try {
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
    } catch (Throwable $e) {
        $suffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    }
    $rows[] = [
        'id' => 'note-' . gmdate('YmdHis') . '-' . $suffix,
        'student_username' => $studentUsername,
        'created_at' => gmdate('c'),
        'note' => $noteText,
        'teacher' => 'Lehrkraft',
    ];
    @write_json_array_file($file, $rows);
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

$files = glob($storageDir . '/letters-*.jsonl') ?: [];
rsort($files, SORT_STRING);

$records = [];
$autoCandidates = [];
$autoApproved = 0;
$autoCutoff = time() - (31 * 60);

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
        $taskPrompt = (string)($record['task_prompt'] ?? '');
        $letterText = (string)($record['letter_text'] ?? '');

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
            'review_status' => 'pending',
            'reviewed_at' => '',
            'review_decision' => '',
            'score_total' => null,
        ];

        $uploadId = (string)($record['upload_id'] ?? '');
        if ($uploadId !== '' && $ts > 0 && $ts <= $autoCutoff && !isset($reviewsByUpload[$uploadId])) {
            $autoCandidates[] = $record;
        }
    }

    fclose($handle);
}

if ($autoCandidates) {
    foreach ($autoCandidates as $candidate) {
        if ($autoApproved >= 50) {
            break;
        }
        $uploadId = trim((string)($candidate['upload_id'] ?? ''));
        if ($uploadId === '' || isset($reviewsByUpload[$uploadId])) {
            continue;
        }
        $letterText = trim((string)($candidate['letter_text'] ?? ''));
        if ($letterText === '') {
            continue;
        }
        $taskPrompt = trim((string)($candidate['task_prompt'] ?? ''));
        $requiredPoints = is_array($candidate['required_points'] ?? null) ? $candidate['required_points'] : [];
        try {
            $correction = dtz_run_correction($letterText, $taskPrompt, $requiredPoints);
        } catch (Throwable $e) {
            continue;
        }
        $reviewRecord = [
            'upload_id' => $uploadId,
            'student_username' => (string)($candidate['student_username'] ?? ''),
            'student_name' => (string)($candidate['student_name'] ?? ''),
            'decision' => 'approve',
            'note' => 'Automatische Freigabe nach 31 Minuten.',
            'reviewed_at' => gmdate('c'),
            'reviewed_by' => 'auto',
            'correction_result' => $correction,
        ];
        if (!append_letter_review($storageDir, $reviewRecord)) {
            continue;
        }
        $reviewsByUpload[$uploadId] = $reviewRecord;
        $autoApproved += 1;

        $score = (int)($correction['score_total'] ?? 0);
        $niveau = (string)($correction['niveau_einschaetzung'] ?? '-');
        append_teacher_note($storageDir, (string)($candidate['student_username'] ?? ''), "Ihr Brief wurde automatisch freigegeben. Ergebnis: {$score}/20 ({$niveau}).");

        append_audit_log('letter_review_auto', [
            'upload_id' => $uploadId,
            'student_username' => (string)($candidate['student_username'] ?? ''),
        ]);
    }
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
