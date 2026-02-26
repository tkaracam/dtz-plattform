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
    echo json_encode(['error' => 'Nur GET wird unterstuetzt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/auth.php';
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

$bskRecords = [];
$simRecords = [];
$letterRecords = [];

foreach (read_jsonl_records($storageDir . '/bsk-*.jsonl') as $row) {
    $u = mb_strtolower(trim((string)($row['student_username'] ?? '')));
    if ($u !== $username) {
        continue;
    }
    $bskRecords[] = [
        'type' => 'BSK',
        'created_at' => (string)($row['created_at'] ?? ''),
        'score_label' => (int)($row['score_correct'] ?? 0) . '/' . max(1, (int)($row['score_total'] ?? 1)),
        'percent' => (int)round(((int)($row['score_correct'] ?? 0) / max(1, (int)($row['score_total'] ?? 1))) * 100),
        'detail' => trim((string)($row['level'] ?? '') . ' ' . (string)($row['field'] ?? '')),
        'field' => (string)($row['field'] ?? ''),
    ];
}

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
    $letterRecords[] = [
        'type' => 'Brief-Upload',
        'created_at' => (string)($row['created_at'] ?? ''),
        'score_label' => '-',
        'percent' => 0,
        'detail' => mb_substr(trim((string)($row['task_prompt'] ?? 'Ohne Aufgabenangabe')), 0, 80),
    ];
}

$allResults = array_merge($simRecords, $bskRecords, $letterRecords);
usort($allResults, static function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});
$allResults = array_slice($allResults, 0, 20);

$homeworkRaw = read_json_file_array($storageDir . '/student_homeworks.json');
$homeworks = [];
foreach ($homeworkRaw as $row) {
    if (!is_array($row)) {
        continue;
    }
    $target = mb_strtolower(trim((string)($row['student_username'] ?? '')));
    if ($target !== '' && $target !== $username && $target !== '*') {
        continue;
    }
    $homeworks[] = [
        'id' => (string)($row['id'] ?? ''),
        'title' => (string)($row['title'] ?? 'Aufgabe'),
        'description' => (string)($row['description'] ?? ''),
        'due_date' => (string)($row['due_date'] ?? ''),
        'status' => (string)($row['status'] ?? 'offen'),
    ];
}

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

$bskPercents = array_map(static fn($r) => (int)($r['percent'] ?? 0), $bskRecords);
$simPercents = array_map(static fn($r) => (int)($r['percent'] ?? 0), $simRecords);
$avgBsk = count($bskPercents) ? (int)round(array_sum($bskPercents) / count($bskPercents)) : 0;
$avgSim = count($simPercents) ? (int)round(array_sum($simPercents) / count($simPercents)) : 0;

$dtzReadiness = (int)round(($avgSim * 0.7) + ($avgBsk * 0.3));
$dtbReadiness = (int)round(($avgBsk * 0.75) + ($avgSim * 0.25));

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
if ($avgBsk < 65) {
    $missingMap['Berufsbezogener Wortschatz'] = ($missingMap['Berufsbezogener Wortschatz'] ?? 0) + 2;
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
    'readiness' => [
        'dtz' => max(0, min(100, $dtzReadiness)),
        'dtb' => max(0, min(100, $dtbReadiness)),
        'missing_topics' => $rankedMissing,
    ],
], JSON_UNESCAPED_UNICODE);
