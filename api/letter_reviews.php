<?php
declare(strict_types=1);

function read_jsonl_records_by_pattern(string $pattern): array
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
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        fclose($handle);
    }
    return $out;
}

function load_letter_reviews_index(string $storageDir): array
{
    $file = $storageDir . '/letter_reviews.jsonl';
    if (!is_file($file)) {
        return [];
    }
    $rows = read_jsonl_records_by_pattern($file);
    $map = [];
    foreach ($rows as $row) {
        $uploadId = trim((string)($row['upload_id'] ?? ''));
        if ($uploadId === '') {
            continue;
        }
        $ts = strtotime((string)($row['reviewed_at'] ?? '')) ?: 0;
        $prevTs = strtotime((string)($map[$uploadId]['reviewed_at'] ?? '')) ?: 0;
        if (!isset($map[$uploadId]) || $ts >= $prevTs) {
            $map[$uploadId] = $row;
        }
    }
    return $map;
}

function append_letter_review(string $storageDir, array $record): bool
{
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        return false;
    }
    $line = json_encode($record, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    return @file_put_contents($storageDir . '/letter_reviews.jsonl', $line, FILE_APPEND | LOCK_EX) !== false;
}

function find_letter_record_by_upload_id(string $storageDir, string $uploadId): ?array
{
    if ($uploadId === '') {
        return null;
    }
    $files = glob($storageDir . '/letters-*.jsonl') ?: [];
    rsort($files, SORT_STRING);
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
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            if (trim((string)($row['upload_id'] ?? '')) === $uploadId) {
                fclose($handle);
                return $row;
            }
        }
        fclose($handle);
    }
    return null;
}
