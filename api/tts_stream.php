<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

start_secure_session();
if (empty($_SESSION['student_authenticated']) && empty($_SESSION['admin_authenticated'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nicht autorisiert.';
    exit;
}

$file = (string)($_GET['f'] ?? '');
if (!preg_match('/^[a-f0-9]{32}\.mp3$/', $file)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ungültige Datei.';
    exit;
}

$path = __DIR__ . '/storage/tts/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nicht gefunden.';
    exit;
}

$size = filesize($path);
if ($size === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Dateifehler.';
    exit;
}

header('Content-Type: audio/mpeg');
header('Content-Length: ' . (string)$size);
header('Cache-Control: public, max-age=86400');
header('Accept-Ranges: bytes');
readfile($path);

