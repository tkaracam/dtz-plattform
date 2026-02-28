<?php
declare(strict_types=1);

function dtz_correction_load_config(): array
{
    $configPath = __DIR__ . '/config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    }

    $apiKey = getenv('OPENAI_API_KEY') ?: '';
    $model = getenv('OPENAI_MODEL') ?: 'gpt-4.1-mini';

    if (defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '') {
        $apiKey = OPENAI_API_KEY;
    }
    if (defined('OPENAI_MODEL') && OPENAI_MODEL !== '') {
        $model = OPENAI_MODEL;
    }

    return [
        'api_key' => $apiKey,
        'model' => $model,
    ];
}

function dtz_is_likely_meaningful_german_text(string $text): bool
{
    $lower = mb_strtolower($text);
    preg_match_all('/[a-zäöüß]+/u', $lower, $m);
    $tokens = array_values(array_filter($m[0] ?? [], static fn($t) => mb_strlen($t) >= 2));
    if (count($tokens) < 8) {
        return true;
    }

    $markers = [
        'ich', 'sie', 'wir', 'und', 'oder', 'aber', 'nicht', 'bitte', 'danke',
        'sehr', 'geehrte', 'mit', 'freundlichen', 'grüßen', 'gruesen', 'habe',
        'möchte', 'moechte', 'kann', 'können', 'koennen', 'weil', 'dass', 'für',
        'fuer', 'eine', 'einen', 'mein', 'meine'
    ];
    $markerSet = array_fill_keys($markers, true);

    $markerHits = 0;
    $hardClusters = 0;
    $longWords = 0;
    $sumLen = 0;

    foreach ($tokens as $token) {
        $sumLen += mb_strlen($token);
        if (isset($markerSet[$token])) {
            $markerHits++;
        }
        if (preg_match('/[bcdfghjklmnpqrstvwxyzß]{5,}/iu', $token) === 1) {
            $hardClusters++;
        }
        if (mb_strlen($token) >= 12) {
            $longWords++;
        }
    }

    if ($markerHits >= 2) {
        return true;
    }

    $count = count($tokens);
    $clusterRatio = $hardClusters / $count;
    $longRatio = $longWords / $count;
    $avgLen = $sumLen / $count;

    if ($clusterRatio > 0.18) {
        return false;
    }
    if ($longRatio > 0.28 && $avgLen > 7.5) {
        return false;
    }
    if ($avgLen > 8.6) {
        return false;
    }
    return true;
}

function dtz_clamp_int($value, int $min, int $max, int $fallback): int
{
    if (!is_numeric($value)) {
        return $fallback;
    }
    $num = (int)$value;
    if ($num < $min) {
        return $min;
    }
    if ($num > $max) {
        return $max;
    }
    return $num;
}

function dtz_keyword_match(string $point, string $letterText): bool
{
    $letterLower = mb_strtolower($letterText);
    preg_match_all('/\p{L}+/u', mb_strtolower($point), $m);
    $tokens = array_values(array_filter($m[0] ?? [], static fn($t) => mb_strlen($t) >= 4));
    if (!$tokens) {
        return str_contains($letterLower, mb_strtolower($point));
    }
    $tokens = array_slice($tokens, 0, 4);
    foreach ($tokens as $token) {
        if (str_contains($letterLower, $token)) {
            return true;
        }
    }
    return false;
}

function dtz_evaluate_points(string $letterText, array $requiredPoints): array
{
    $covered = [];
    $missing = [];
    foreach ($requiredPoints as $point) {
        if (dtz_keyword_match($point, $letterText)) {
            $covered[] = $point;
        } else {
            $missing[] = $point;
        }
    }
    return ['covered' => $covered, 'missing' => $missing];
}

function dtz_find_line_number(string $letterText, string $snippet): int
{
    $snippet = trim($snippet);
    if ($snippet === '') {
        return 1;
    }
    $lines = preg_split('/\R/u', $letterText) ?: [];
    $needle = mb_strtolower($snippet);
    foreach ($lines as $idx => $line) {
        if (str_contains(mb_strtolower($line), $needle)) {
            return $idx + 1;
        }
    }
    return 1;
}

function dtz_normalize_mistakes($rawMistakes, string $letterText): array
{
    if (!is_array($rawMistakes)) {
        return [];
    }
    $out = [];
    foreach ($rawMistakes as $item) {
        if (!is_array($item)) {
            continue;
        }
        $original = trim((string)($item['original'] ?? ''));
        $out[] = [
            'line' => dtz_clamp_int($item['line'] ?? null, 1, 9999, dtz_find_line_number($letterText, $original)),
            'original' => $original,
            'correction' => trim((string)($item['correction'] ?? '')),
            'type' => trim((string)($item['type'] ?? 'Sonstiges')) ?: 'Sonstiges',
            'explanation_de' => trim((string)($item['explanation_de'] ?? '')),
        ];
    }
    return $out;
}

function dtz_normalize_payload(array $payload, string $letterText, array $requiredPoints, string $source): array
{
    $rubricRaw = is_array($payload['rubric'] ?? null) ? $payload['rubric'] : [];
    $rubric = [
        'aufgabenbezug' => dtz_clamp_int($rubricRaw['aufgabenbezug'] ?? null, 0, 5, 0),
        'textaufbau' => dtz_clamp_int($rubricRaw['textaufbau'] ?? null, 0, 5, 0),
        'grammatik' => dtz_clamp_int($rubricRaw['grammatik'] ?? null, 0, 5, 0),
        'wortschatz_orthografie' => dtz_clamp_int($rubricRaw['wortschatz_orthografie'] ?? null, 0, 5, 0),
    ];
    $score = dtz_clamp_int($payload['score_total'] ?? null, 0, 20, array_sum($rubric));

    $niveau = trim((string)($payload['niveau_einschaetzung'] ?? ''));
    if ($niveau === '') {
        if ($score >= 16) {
            $niveau = 'B1';
        } elseif ($score >= 10) {
            $niveau = 'A2-B1';
        } else {
            $niveau = 'A2';
        }
    }

    preg_match_all('/\S+/u', trim($letterText), $wordMatches);
    $wordCount = count($wordMatches[0] ?? []);
    if ($wordCount < 20) {
        $score = min($score, 6);
        $niveau = 'A2';
    } elseif ($wordCount < 40) {
        $score = min($score, 10);
        if ($niveau === 'B1') {
            $niveau = 'A2-B1';
        }
    }
    if ($niveau === 'B1' && ($wordCount < 50 || $wordCount > 80)) {
        $niveau = 'A2-B1';
        $score = min($score, 15);
    }

    $points = dtz_evaluate_points($letterText, $requiredPoints);
    $corrected = trim((string)($payload['corrected_letter'] ?? ''));
    if ($corrected === '') {
        $corrected = trim($letterText);
    }
    $feedbackDe = trim((string)($payload['teacher_feedback_de'] ?? ''));
    if ($feedbackDe === '') {
        $feedbackDe = 'Bitte arbeiten Sie die Fehlerliste durch.';
    }

    return [
        'score_total' => $score,
        'niveau_einschaetzung' => $niveau,
        'rubric' => $rubric,
        'mistakes' => dtz_normalize_mistakes($payload['mistakes'] ?? [], $letterText),
        'corrected_letter' => $corrected,
        'teacher_feedback_de' => $feedbackDe,
        'covered_points' => $points['covered'],
        'missing_points' => $points['missing'],
        'source' => $source,
        'system_note' => '',
    ];
}

function dtz_extract_json_text(string $raw): array
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        throw new RuntimeException('OpenAI-Antwort ist leer.');
    }

    if (str_starts_with($trimmed, '```')) {
        $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        $trimmed = trim($trimmed);
    }

    $data = json_decode($trimmed, true);
    if (is_array($data)) {
        return $data;
    }

    if (preg_match('/\{[\s\S]*\}/', $trimmed, $m) === 1) {
        $data = json_decode($m[0], true);
        if (is_array($data)) {
            return $data;
        }
    }

    throw new RuntimeException('Ungueltiges JSON in der OpenAI-Antwort.');
}

function dtz_sanitize_external_error(string $message): string
{
    $clean = trim($message);
    if ($clean === '') {
        return 'Externer Dienstfehler.';
    }
    $clean = preg_replace('/sk-[A-Za-z0-9_\-]{10,}/', 'sk-***', $clean);
    $clean = preg_replace('/\b[A-Za-z0-9]{20,}\b/', '***', $clean);
    return $clean ?? 'Externer Dienstfehler.';
}

function dtz_run_correction(string $letterText, string $taskPrompt, array $requiredPoints): array
{
    $config = dtz_correction_load_config();
    $apiKey = (string)($config['api_key'] ?? '');
    $model = (string)($config['model'] ?? 'gpt-4.1-mini');

    if ($apiKey === '') {
        throw new RuntimeException('OPENAI_API_KEY wurde nicht gefunden.');
    }

    if (!dtz_is_likely_meaningful_german_text($letterText)) {
        throw new RuntimeException('Der Text wirkt nicht sinnvoll (zufällige Zeichen/Wörter). Bitte einen verständlichen Brief eingeben.');
    }

    $requiredPoints = array_values(array_filter(array_map(
        static fn($item) => trim((string)$item),
        is_array($requiredPoints) ? $requiredPoints : []
    ), static fn($item) => $item !== ''));

    $pointsText = '';
    foreach ($requiredPoints as $point) {
        $pointsText .= "- {$point}\n";
    }
    if ($pointsText === '') {
        $pointsText = "- (Keine Punkte angegeben)\n";
    }

    $taskText = $taskPrompt !== '' ? $taskPrompt : 'Keine Aufgabenstellung angegeben.';
    $systemPrompt = <<<SYS
Du bist eine erfahrene Deutschlehrkraft fuer DTZ Schreiben.
Antworte ausschliesslich mit gueltigem JSON.
Bewerte konstruktiv, klar und auf DTZ-Niveau.
SYS;

    $userPrompt = <<<USR
Bewerte den folgenden Schülerbrief nach den DTZ-Schreiben-Kriterien:

Aufgabenstellung:
{$taskText}

Pflichtpunkte:
{$pointsText}

Schülerbrief:
{$letterText}

Antworte nur in diesem JSON-Format:
{
  "score_total": 0,
  "niveau_einschaetzung": "A2|A2-B1|B1",
  "rubric": {
    "aufgabenbezug": 0,
    "textaufbau": 0,
    "grammatik": 0,
    "wortschatz_orthografie": 0
  },
  "mistakes": [
    {
      "line": 1,
      "original": "fehlerhafte Stelle",
      "correction": "korrigierte Form",
      "type": "Grammatik|Rechtschreibung|Aufgabenbezug|Textaufbau|Wortschatz",
      "explanation_de": "Kurze Erklärung auf Deutsch"
    }
  ],
  "corrected_letter": "Vollständig korrigierter deutscher Brief",
  "teacher_feedback_de": "Allgemeines Feedback auf Deutsch"
}

Regeln:
- Gesamtpunktzahl zwischen 0 und 20.
- Jede Rubrikkategorie zwischen 0 und 5.
- Der korrigierte Brief soll DTZ-gerecht, klar und natürlich sein.
- Schreibe nichts ausserhalb des JSON.
USR;

    $payload = [
        'model' => $model,
        'temperature' => 0.2,
        'input' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 45,
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('OpenAI-Verbindungsfehler: ' . dtz_sanitize_external_error($err));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode((string)$resp, true);
    if (!is_array($json)) {
        throw new RuntimeException('OpenAI-Antwort konnte nicht gelesen werden.');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = $json['error']['message'] ?? 'OpenAI-Anfrage fehlgeschlagen.';
        throw new RuntimeException(dtz_sanitize_external_error((string)$msg));
    }

    $rawText = '';
    if (!empty($json['output_text']) && is_string($json['output_text'])) {
        $rawText = $json['output_text'];
    } elseif (!empty($json['output']) && is_array($json['output'])) {
        $parts = [];
        foreach ($json['output'] as $item) {
            if (!is_array($item) || empty($item['content']) || !is_array($item['content'])) {
                continue;
            }
            foreach ($item['content'] as $content) {
                if (!is_array($content)) {
                    continue;
                }
                if (isset($content['text']) && is_string($content['text'])) {
                    $parts[] = $content['text'];
                } elseif (isset($content['text']['value']) && is_string($content['text']['value'])) {
                    $parts[] = $content['text']['value'];
                }
            }
        }
        $rawText = trim(implode("\n", $parts));
    }

    if ($rawText === '') {
        throw new RuntimeException('Das Modell hat keinen Text zurückgegeben.');
    }

    $teacherPayload = dtz_extract_json_text($rawText);
    return dtz_normalize_payload($teacherPayload, $letterText, $requiredPoints, 'api-ai');
}
