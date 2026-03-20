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
    echo json_encode(['error' => 'Nur POST wird unterstützt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/auth.php';

// Nur angemeldete Schüler dürfen TTS erzeugen.
require_student_session_json();

$configPath = __DIR__ . '/config.php';
if (is_file($configPath)) {
    require_once $configPath;
}

$apiKey = getenv('OPENAI_API_KEY') ?: (defined('OPENAI_API_KEY') ? (string) OPENAI_API_KEY : '');
if ($apiKey === '') {
    http_response_code(503);
    echo json_encode([
        'error' => 'OPENAI_API_KEY fehlt.',
        'provider' => 'none',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$ttsModel = getenv('OPENAI_TTS_MODEL') ?: (defined('OPENAI_TTS_MODEL') ? (string) OPENAI_TTS_MODEL : 'gpt-4o-mini-tts');
$defaultVoice = getenv('OPENAI_TTS_VOICE') ?: (defined('OPENAI_TTS_VOICE') ? (string) OPENAI_TTS_VOICE : 'alloy');

$raw = file_get_contents('php://input') ?: '';
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges JSON.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$script = trim((string) ($decoded['script'] ?? ''));
if ($script === '') {
    http_response_code(400);
    echo json_encode(['error' => 'script fehlt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function tts_normalize_text(string $text): string
{
    $text = str_replace(['Ae', 'Oe', 'Ue', 'ae', 'oe', 'ue'], ['Ä', 'Ö', 'Ü', 'ä', 'ö', 'ü'], $text);
    $text = preg_replace('/\s+([A-D]):/u', '. $1:', $text) ?? $text;
    $text = preg_replace('/\b(wegen)\s+\1\b/iu', '$1', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function tts_normalize_speaker_tag(string $speaker): string
{
    $key = mb_strtoupper(trim((string)preg_replace('/[._-]+/u', ' ', $speaker)));
    $key = trim((string)preg_replace('/\s+/u', ' ', $key));
    if ($key === '') {
        return 'NARRATOR';
    }
    if (preg_match('/^(A|B|C|D)$/u', $key) === 1) {
        return $key;
    }
    if (preg_match('/^SPRECHER\s*\d+$/u', $key) === 1) {
        return (string)preg_replace('/\s+/u', ' ', $key);
    }
    if (preg_match('/^(NARRATOR|MODERATOR|ANSAGE)$/u', $key) === 1) {
        return 'NARRATOR';
    }
    if (preg_match('/^(WOMAN|FRAU)\s*(\d+)$/u', $key, $m) === 1) {
        return 'WOMAN ' . $m[2];
    }
    if (preg_match('/^(MAN|MANN)\s*(\d+)$/u', $key, $m) === 1) {
        return 'MAN ' . $m[2];
    }
    if (preg_match('/^SPEAKER\s*(\d+)$/u', $key, $m) === 1) {
        return 'SPEAKER ' . $m[1];
    }
    return 'NARRATOR';
}

function tts_strip_leading_speaker_token(string $text): string
{
    $stripped = preg_replace(
        '/^((?:A|B|C|D|SPRECHER\s*\d+|SPEAKER\s*\d+|WOMAN[_\s-]*\d+|MAN[_\s-]*\d+|NARRATOR|MODERATOR|ANSAGE))\s*:\s*/iu',
        '',
        trim($text)
    );
    return trim((string)$stripped);
}

function tts_split_segments(string $script): array
{
    $normalized = tts_normalize_text($script);
    if ($normalized === '') {
        return [];
    }

    $withBreaks = preg_replace('/\s+(?=(?:[A-D]|Sprecher\s*\d+|Speaker\s*\d+|Woman\s*[_-]?\s*\d+|Man\s*[_-]?\s*\d+|Moderator|Ansage|Narrator)\s*:)/iu', "\n", $normalized);
    $speakerChunks = preg_split('/\n+/u', (string) $withBreaks) ?: [];

    $segments = [];
    foreach ($speakerChunks as $chunk) {
        $chunk = trim((string) $chunk);
        if ($chunk === '') {
            continue;
        }
        $speaker = 'NARRATOR';
        $textBlock = $chunk;
        if (preg_match('/^((?:[A-D]|Sprecher\s*\d+|Speaker\s*\d+|Woman\s*[_-]?\s*\d+|Man\s*[_-]?\s*\d+|Moderator|Ansage|Narrator))\s*:\s*(.+)$/iu', $chunk, $m) === 1) {
            $speaker = tts_normalize_speaker_tag((string)$m[1]);
            $textBlock = tts_strip_leading_speaker_token((string)$m[2]);
        } else {
            $textBlock = tts_strip_leading_speaker_token($textBlock);
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $textBlock) ?: [$textBlock];
        foreach ($sentences as $sentence) {
            $sentence = trim((string) $sentence);
            if ($sentence === '') {
                continue;
            }
            if (mb_strlen($sentence) > 170 && str_contains($sentence, ',')) {
                $parts = array_values(array_filter(array_map(
                    static fn(string $v): string => trim($v),
                    explode(',', $sentence)
                ), static fn(string $v): bool => $v !== ''));
                foreach ($parts as $idx => $part) {
                    $segments[] = [
                        'speaker' => $speaker,
                        'text' => $idx < count($parts) - 1 ? ($part . ',') : $part,
                    ];
                }
            } else {
                $segments[] = ['speaker' => $speaker, 'text' => $sentence];
            }
        }
    }

    return $segments;
}

function tts_voice_for_speaker(string $speaker, string $defaultVoice): string
{
    $speakerKey = mb_strtoupper(trim($speaker));
    $map = [
        'A' => getenv('OPENAI_TTS_VOICE_A') ?: 'alloy',
        'B' => getenv('OPENAI_TTS_VOICE_B') ?: 'verse',
        'C' => getenv('OPENAI_TTS_VOICE_C') ?: 'echo',
        'D' => getenv('OPENAI_TTS_VOICE_D') ?: 'fable',
        'WOMAN 1' => getenv('OPENAI_TTS_VOICE_WOMAN_1') ?: (getenv('OPENAI_TTS_VOICE_B') ?: 'verse'),
        'WOMAN 2' => getenv('OPENAI_TTS_VOICE_WOMAN_2') ?: (getenv('OPENAI_TTS_VOICE_D') ?: 'fable'),
        'MAN 1' => getenv('OPENAI_TTS_VOICE_MAN_1') ?: (getenv('OPENAI_TTS_VOICE_A') ?: 'alloy'),
        'MAN 2' => getenv('OPENAI_TTS_VOICE_MAN_2') ?: (getenv('OPENAI_TTS_VOICE_C') ?: 'echo'),
        'SPEAKER 1' => getenv('OPENAI_TTS_VOICE_SPEAKER_1') ?: (getenv('OPENAI_TTS_VOICE_A') ?: 'alloy'),
        'SPEAKER 2' => getenv('OPENAI_TTS_VOICE_SPEAKER_2') ?: (getenv('OPENAI_TTS_VOICE_B') ?: 'verse'),
        'NARRATOR' => getenv('OPENAI_TTS_VOICE_NARRATOR') ?: $defaultVoice,
    ];
    return $map[$speakerKey] ?? $defaultVoice;
}

function tts_pause_for_segment(string $speaker, string $text): int
{
    $pause = 130;
    $speakerKey = mb_strtoupper(trim($speaker));
    if (preg_match('/^(A|B|C|D|WOMAN\s+\d+|MAN\s+\d+|SPEAKER\s+\d+)$/u', $speakerKey) === 1) {
        $pause = 190;
    }
    if (preg_match('/[!?]$/u', trim($text)) === 1) {
        $pause += 60;
    }
    return $pause;
}

function tts_cache_dir(): string
{
    return __DIR__ . '/storage/tts';
}

function tts_cache_filename(string $model, string $voice, string $text): string
{
    $hash = md5($model . '|' . $voice . '|' . $text);
    return $hash . '.mp3';
}

function tts_openai_generate(string $apiKey, string $model, string $voice, string $text): array
{
    $payload = [
        'model' => $model,
        'voice' => $voice,
        'input' => $text,
        'format' => 'mp3',
    ];

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    if ($ch === false) {
        return ['ok' => false, 'error' => 'cURL konnte nicht initialisiert werden.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => 'OpenAI-TTS cURL Fehler: ' . $curlError];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $sample = trim((string) $body);
        $sample = mb_substr(preg_replace('/\s+/u', ' ', $sample) ?? $sample, 0, 200);
        return ['ok' => false, 'error' => 'OpenAI-TTS HTTP ' . $httpCode . ': ' . $sample];
    }

    return ['ok' => true, 'audio' => $body];
}

$segments = tts_split_segments($script);
if (!$segments) {
    http_response_code(400);
    echo json_encode(['error' => 'Keine Audio-Segmente gefunden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cacheDir = tts_cache_dir();
if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'TTS-Cache konnte nicht erstellt werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$outSegments = [];
foreach ($segments as $segment) {
    $text = trim((string) ($segment['text'] ?? ''));
    if ($text === '') {
        continue;
    }
    $speaker = (string) ($segment['speaker'] ?? 'NARRATOR');
    $voice = tts_voice_for_speaker($speaker, $defaultVoice);
    $file = tts_cache_filename($ttsModel, $voice, $text);
    $fullPath = $cacheDir . '/' . $file;

    if (!is_file($fullPath) || filesize($fullPath) < 1000) {
        $generated = tts_openai_generate($apiKey, $ttsModel, $voice, $text);
        if (!$generated['ok']) {
            // Fallback auf default voice (falls einzelne Stimme im Account nicht verfügbar ist)
            if ($voice !== $defaultVoice) {
                $voice = $defaultVoice;
                $file = tts_cache_filename($ttsModel, $voice, $text);
                $fullPath = $cacheDir . '/' . $file;
                if (!is_file($fullPath) || filesize($fullPath) < 1000) {
                    $generated = tts_openai_generate($apiKey, $ttsModel, $voice, $text);
                } else {
                    $generated = ['ok' => true, 'audio' => null];
                }
            }
        }

        if (!$generated['ok']) {
            http_response_code(502);
            echo json_encode([
                'error' => $generated['error'],
                'provider' => 'openai',
                'mode' => 'server',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (isset($generated['audio']) && is_string($generated['audio']) && $generated['audio'] !== '') {
            file_put_contents($fullPath, $generated['audio']);
        }
    }

    $outSegments[] = [
        'speaker' => $speaker,
        'voice' => $voice,
        'pause_ms' => tts_pause_for_segment($speaker, $text),
        'url' => './api/tts_stream.php?f=' . rawurlencode($file),
    ];
}

echo json_encode([
    'ok' => true,
    'provider' => 'openai',
    'mode' => 'server',
    'model' => $ttsModel,
    'segments' => $outSegments,
], JSON_UNESCAPED_UNICODE);
