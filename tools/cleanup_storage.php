<?php
declare(strict_types=1);

// Usage:
// php tools/cleanup_storage.php 90

$days = isset($argv[1]) ? (int)$argv[1] : 90;
if ($days < 1) {
    $days = 90;
}

$storageDir = __DIR__ . '/../api/storage';
if (!is_dir($storageDir)) {
    fwrite(STDOUT, "storage dir not found: {$storageDir}\n");
    exit(0);
}

$cutoff = strtotime("-{$days} days");
$patterns = [
    $storageDir . '/letters-*.jsonl',
    $storageDir . '/bsk-*.jsonl',
    $storageDir . '/ratelimit-*.json',
];

$deleted = 0;
$checked = 0;

foreach ($patterns as $pattern) {
    $files = glob($pattern) ?: [];
    foreach ($files as $file) {
        $checked++;
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $cutoff) {
            if (@unlink($file)) {
                $deleted++;
            }
        }
    }
}

fwrite(STDOUT, "checked={$checked} deleted={$deleted} retention_days={$days}\n");
