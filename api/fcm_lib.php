<?php
declare(strict_types=1);

function fcm_tokens_file_path_lib(): string
{
    return __DIR__ . '/storage/fcm_tokens.json';
}

function load_fcm_tokens_lib(): array
{
    $file = fcm_tokens_file_path_lib();
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

function send_fcm_notification_legacy(array $tokens, string $title, string $body, array $data = []): array
{
    $serverKey = getenv('FCM_SERVER_KEY') ?: '';
    if (defined('FCM_SERVER_KEY') && FCM_SERVER_KEY !== '') {
        $serverKey = (string)FCM_SERVER_KEY;
    }
    if ($serverKey === '') {
        return ['ok' => false, 'error' => 'FCM_SERVER_KEY fehlt.'];
    }

    $tokens = array_values(array_unique(array_filter(array_map('strval', $tokens), static fn($t): bool => trim($t) !== '')));
    if (!$tokens) {
        return ['ok' => true, 'success' => 0, 'failure' => 0];
    }

    $payload = [
        'registration_ids' => $tokens,
        'notification' => [
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
        ],
        'data' => $data,
        'priority' => 'high',
    ];

    $ch = curl_init('https://fcm.googleapis.com/fcm/send');
    if ($ch === false) {
        return ['ok' => false, 'error' => 'cURL konnte nicht gestartet werden.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: key=' . $serverKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $curlErr !== '') {
        return ['ok' => false, 'error' => 'FCM cURL hatasi: ' . $curlErr];
    }

    $decoded = json_decode((string)$resp, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'FCM yaniti çözülemedi.', 'http_code' => $code];
    }

    return [
        'ok' => ($code >= 200 && $code < 300),
        'http_code' => $code,
        'success' => (int)($decoded['success'] ?? 0),
        'failure' => (int)($decoded['failure'] ?? 0),
        'results' => is_array($decoded['results'] ?? null) ? $decoded['results'] : [],
    ];
}

