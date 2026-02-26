<?php
declare(strict_types=1);

function start_secure_session(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    } else {
        ini_set('session.cookie_lifetime', '0');
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.cookie_httponly', '1');
        session_set_cookie_params(0, '/');
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['last_activity_at'])) {
        $_SESSION['last_activity_at'] = time();
    }
}

function require_admin_session_json(): void
{
    start_secure_session();
    enforce_session_timeout_json();
    if (empty($_SESSION['admin_authenticated'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Nicht autorisiert. Bitte zuerst als Lehrkraft anmelden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function require_student_session_json(): array
{
    start_secure_session();
    enforce_session_timeout_json();
    if (empty($_SESSION['student_authenticated']) || empty($_SESSION['student_username'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Nicht autorisiert. Bitte zuerst als Schueler anmelden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return [
        'username' => (string)$_SESSION['student_username'],
        'display_name' => (string)($_SESSION['student_display_name'] ?? ''),
    ];
}

function student_users_file(): string
{
    return __DIR__ . '/storage/student_users.json';
}

function load_student_users(): array
{
    $file = student_users_file();
    if (!file_exists($file)) {
        return [];
    }

    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function write_student_users(array $users): bool
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode(array_values($users), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    return file_put_contents(student_users_file(), $json . PHP_EOL, LOCK_EX) !== false;
}

function enforce_session_timeout_json(): void
{
    $ttl = 45 * 60;
    $now = time();
    $last = (int)($_SESSION['last_activity_at'] ?? $now);
    if ($last > 0 && ($now - $last) > $ttl) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
        }
        session_destroy();
        http_response_code(401);
        echo json_encode(['error' => 'Sitzung abgelaufen. Bitte erneut anmelden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION['last_activity_at'] = $now;
}

function get_client_ip(): string
{
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        $raw = trim((string)($_SERVER[$key] ?? ''));
        if ($raw === '') {
            continue;
        }
        $ip = explode(',', $raw)[0];
        $ip = trim($ip);
        if ($ip !== '') {
            return $ip;
        }
    }
    return '0.0.0.0';
}

function rate_limit_file(string $bucket): string
{
    $safe = preg_replace('/[^a-z0-9._-]+/i', '-', strtolower(trim($bucket)));
    return __DIR__ . '/storage/ratelimit-' . ($safe !== '' ? $safe : 'default') . '.json';
}

function check_rate_limit_json(string $bucket, int $maxAttempts, int $windowSeconds): void
{
    $ip = get_client_ip();
    $now = time();
    $file = rate_limit_file($bucket);
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }

    $data = [];
    if (is_file($file)) {
        $raw = file_get_contents($file);
        $tmp = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($tmp)) {
            $data = $tmp;
        }
    }

    $cutoff = $now - $windowSeconds;
    foreach ($data as $k => $item) {
        $ts = is_array($item) ? (int)($item['first_at'] ?? 0) : 0;
        if ($ts < $cutoff) {
            unset($data[$k]);
        }
    }

    $entry = is_array($data[$ip] ?? null) ? $data[$ip] : ['count' => 0, 'first_at' => $now];
    if ((int)$entry['first_at'] < $cutoff) {
        $entry = ['count' => 0, 'first_at' => $now];
    }

    if ((int)$entry['count'] >= $maxAttempts) {
        http_response_code(429);
        echo json_encode(['error' => 'Zu viele Versuche. Bitte spaeter erneut versuchen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function register_rate_limit_failure(string $bucket): void
{
    $ip = get_client_ip();
    $now = time();
    $file = rate_limit_file($bucket);
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }

    $data = [];
    if (is_file($file)) {
        $raw = file_get_contents($file);
        $tmp = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($tmp)) {
            $data = $tmp;
        }
    }

    $entry = is_array($data[$ip] ?? null) ? $data[$ip] : ['count' => 0, 'first_at' => $now];
    $entry['count'] = (int)($entry['count'] ?? 0) + 1;
    if (empty($entry['first_at'])) {
        $entry['first_at'] = $now;
    }
    $data[$ip] = $entry;
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function clear_rate_limit_failures(string $bucket): void
{
    $ip = get_client_ip();
    $file = rate_limit_file($bucket);
    if (!is_file($file)) {
        return;
    }
    $raw = file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return;
    }
    unset($data[$ip]);
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function courses_file(): string
{
    return __DIR__ . '/storage/courses.json';
}

function load_courses(): array
{
    $file = courses_file();
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function write_courses(array $courses): bool
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode(array_values($courses), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return file_put_contents(courses_file(), $json . PHP_EOL, LOCK_EX) !== false;
}

function append_audit_log(string $action, array $meta = []): void
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        start_secure_session();
    }

    $actorType = 'anonymous';
    $actorId = '';
    if (!empty($_SESSION['admin_authenticated'])) {
        $actorType = 'admin';
        $actorId = 'admin';
    } elseif (!empty($_SESSION['student_authenticated']) && !empty($_SESSION['student_username'])) {
        $actorType = 'student';
        $actorId = (string)($_SESSION['student_username'] ?? '');
    }

    $record = [
        'created_at' => gmdate('c'),
        'action' => $action,
        'actor_type' => $actorType,
        'actor_id' => $actorId,
        'ip' => get_client_ip(),
        'meta' => $meta,
    ];

    $line = json_encode($record, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents($dir . '/audit_log.jsonl', $line, FILE_APPEND | LOCK_EX);
}
