<?php
declare(strict_types=1);

function auth_lower_text(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($trimmed, 'UTF-8');
    }
    return strtolower($trimmed);
}

function start_secure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $requestedAdminMode = auth_lower_text((string)($_SERVER['HTTP_X_ADMIN_MODE'] ?? ''));
        if ($requestedAdminMode === '') {
            $requestedAdminMode = auth_lower_text((string)($_GET['mode'] ?? ''));
        }
        if ($requestedAdminMode === 'docent') {
            session_name('DTZSESSID_DOCENT');
        } elseif ($requestedAdminMode === 'owner' || $requestedAdminMode === 'hauptadmin') {
            session_name('DTZSESSID_OWNER');
        } else {
            $hasDocentCookie = isset($_COOKIE['DTZSESSID_DOCENT']);
            $hasOwnerCookie = isset($_COOKIE['DTZSESSID_OWNER']);
            if ($hasDocentCookie && !$hasOwnerCookie) {
                session_name('DTZSESSID_DOCENT');
            } elseif ($hasOwnerCookie && !$hasDocentCookie) {
                session_name('DTZSESSID_OWNER');
            }
        }
    }

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

function bsk_module_enabled(): bool
{
    $value = getenv('BSK_MODULE_ENABLED');
    if (($value === false || trim((string)$value) === '') && defined('BSK_MODULE_ENABLED')) {
        $value = (string)BSK_MODULE_ENABLED;
    }
    $normalized = auth_lower_text((string)$value);
    if ($normalized === '') {
        return false;
    }
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function archived_module_enabled(string $module): bool
{
    $normalizedModule = auth_lower_text($module);
    $defaults = [
        'attendance' => false,
        'notify' => false,
        'room' => false,
    ];
    $default = $defaults[$normalizedModule] ?? true;

    $envName = strtoupper($normalizedModule) . '_MODULE_ENABLED';
    $value = getenv($envName);
    if (($value === false || trim((string)$value) === '') && defined($envName)) {
        $value = constant($envName);
    }

    $normalized = auth_lower_text((string)$value);
    if ($normalized === '') {
        return $default;
    }
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function require_archived_module_enabled_json(string $module): void
{
    if (archived_module_enabled($module)) {
        return;
    }
    http_response_code(404);
    echo json_encode(['error' => 'Dieses Modul ist archiviert und nicht erreichbar.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function require_bsk_module_enabled_json(): void
{
    if (bsk_module_enabled()) {
        return;
    }
    http_response_code(503);
    echo json_encode(['error' => 'BSK-Modul ist derzeit deaktiviert (archiviert).'], JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_admin_role_key(string $role): string
{
    $normalized = auth_lower_text($role);
    if (in_array($normalized, ['owner', 'hauptadmin', 'haupt-admin', 'mainadmin'], true)) {
        return 'hauptadmin';
    }
    if ($normalized === 'docent') {
        return 'docent';
    }
    return '';
}

function admin_role_key_to_legacy(string $roleKey): string
{
    return normalize_admin_role_key($roleKey) === 'docent' ? 'docent' : 'owner';
}

function admin_is_hauptadmin(array $adminCtx): bool
{
    $roleKey = normalize_admin_role_key((string)($adminCtx['role_key'] ?? ''));
    if ($roleKey !== '') {
        return $roleKey === 'hauptadmin';
    }
    return auth_lower_text((string)($adminCtx['role'] ?? '')) === 'owner';
}

function require_admin_session_json(): array
{
    start_secure_session();
    enforce_session_timeout_json();
    if (empty($_SESSION['admin_authenticated'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Nicht autorisiert. Bitte zuerst als Lehrkraft anmelden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sessionRole = (string)($_SESSION['admin_role'] ?? '');
    $sessionRoleKey = (string)($_SESSION['admin_role_key'] ?? '');
    $roleKey = normalize_admin_role_key($sessionRoleKey !== '' ? $sessionRoleKey : $sessionRole);
    if ($roleKey === '') {
        $roleKey = 'hauptadmin';
    }
    $role = admin_role_key_to_legacy($roleKey);
    $username = auth_lower_text((string)($_SESSION['admin_username'] ?? ''));
    if ($username === '' && $roleKey === 'hauptadmin') {
        $username = 'admin';
    }
    $displayName = trim((string)($_SESSION['admin_display_name'] ?? ''));
    return [
        'role' => $role,
        'role_key' => $roleKey,
        'username' => $username,
        'display_name' => $displayName,
        'is_owner' => $roleKey === 'hauptadmin',
        'is_hauptadmin' => $roleKey === 'hauptadmin',
    ];
}

function require_owner_session_json(): array
{
    $ctx = require_admin_session_json();
    if (!admin_is_hauptadmin($ctx)) {
        http_response_code(403);
        echo json_encode(['error' => 'Nur der Haupt-Admin darf diese Aktion ausführen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $ctx;
}

function require_hauptadmin_session_json(): array
{
    return require_owner_session_json();
}

function require_admin_role_json(array $allowedRoleKeys): array
{
    $ctx = require_admin_session_json();
    $allowed = [];
    foreach ($allowedRoleKeys as $roleKey) {
        $normalized = normalize_admin_role_key((string)$roleKey);
        if ($normalized !== '') {
            $allowed[$normalized] = true;
        }
    }
    if (!$allowed) {
        http_response_code(500);
        echo json_encode(['error' => 'Konfigurationsfehler: keine gültigen Rollen freigegeben.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $current = normalize_admin_role_key((string)($ctx['role_key'] ?? ''));
    if ($current === '' || empty($allowed[$current])) {
        http_response_code(403);
        echo json_encode(['error' => 'Keine Berechtigung für diese Aktion.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return $ctx;
}

function require_student_session_json(): array
{
    start_secure_session();
    enforce_session_timeout_json();
    if (empty($_SESSION['student_authenticated']) || empty($_SESSION['student_username'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Nicht autorisiert. Bitte zuerst als Schüler anmelden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return [
        'role' => 'student',
        'role_key' => 'schueler',
        'username' => (string)$_SESSION['student_username'],
        'display_name' => (string)($_SESSION['student_display_name'] ?? ''),
        'teacher_username' => auth_lower_text((string)($_SESSION['student_teacher_username'] ?? '')),
    ];
}

function student_users_file(): string
{
    return __DIR__ . '/storage/student_users.json';
}

function member_users_file(): string
{
    return __DIR__ . '/storage/member_users.json';
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

function student_nicknames_file(): string
{
    return __DIR__ . '/storage/student_nicknames.json';
}

function load_student_nicknames(): array
{
    $file = student_nicknames_file();
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function write_student_nicknames(array $data): bool
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    return file_put_contents(student_nicknames_file(), $json . PHP_EOL, LOCK_EX) !== false;
}

function get_docent_student_nickname(string $docentUsername, string $studentUsername): string
{
    $docent = auth_lower_text($docentUsername);
    $student = auth_lower_text($studentUsername);
    if ($docent === '' || $student === '') {
        return '';
    }
    $all = load_student_nicknames();
    if (!is_array($all[$docent] ?? null)) {
        return '';
    }
    $nick = trim((string)($all[$docent][$student] ?? ''));
    return $nick;
}

function set_docent_student_nickname(string $docentUsername, string $studentUsername, string $nickname): bool
{
    $docent = auth_lower_text($docentUsername);
    $student = auth_lower_text($studentUsername);
    if ($docent === '' || $student === '') {
        return false;
    }
    $all = load_student_nicknames();
    if (!is_array($all[$docent] ?? null)) {
        $all[$docent] = [];
    }
    $nick = trim($nickname);
    if ($nick === '') {
        unset($all[$docent][$student]);
        if (empty($all[$docent])) {
            unset($all[$docent]);
        }
    } else {
        $all[$docent][$student] = mb_substr($nick, 0, 64);
    }
    return write_student_nicknames($all);
}

function remove_student_nickname_for_all_docents(string $studentUsername): bool
{
    $student = auth_lower_text($studentUsername);
    if ($student === '') {
        return false;
    }
    $all = load_student_nicknames();
    $changed = false;
    foreach ($all as $docent => $map) {
        if (!is_array($map)) {
            continue;
        }
        if (array_key_exists($student, $map)) {
            unset($all[$docent][$student]);
            $changed = true;
        }
        if (empty($all[$docent])) {
            unset($all[$docent]);
            $changed = true;
        }
    }
    if (!$changed) {
        return true;
    }
    return write_student_nicknames($all);
}

function load_member_users(): array
{
    $file = member_users_file();
    if (!is_file($file)) {
        return [];
    }

    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function write_member_users(array $users): bool
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode(array_values($users), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    return file_put_contents(member_users_file(), $json . PHP_EOL, LOCK_EX) !== false;
}

function teacher_users_file(): string
{
    return __DIR__ . '/storage/teacher_users.json';
}

function load_teacher_users(): array
{
    $file = teacher_users_file();
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function write_teacher_users(array $users): bool
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode(array_values($users), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    return file_put_contents(teacher_users_file(), $json . PHP_EOL, LOCK_EX) !== false;
}

function find_student_user_by_username(string $username): ?array
{
    $needle = auth_lower_text($username);
    if ($needle === '') {
        return null;
    }
    foreach (load_student_users() as $user) {
        if (!is_array($user)) {
            continue;
        }
        $uname = auth_lower_text((string)($user['username'] ?? ''));
        if ($uname === $needle) {
            return $user;
        }
    }
    return null;
}

function find_member_user_by_username(string $username): ?array
{
    $needle = auth_lower_text($username);
    if ($needle === '') {
        return null;
    }
    foreach (load_member_users() as $user) {
        if (!is_array($user)) {
            continue;
        }
        $uname = auth_lower_text((string)($user['username'] ?? ''));
        if ($uname === $needle) {
            return $user;
        }
    }
    return null;
}

function admin_can_access_student_record(array $student, array $adminCtx): bool
{
    if (admin_is_hauptadmin($adminCtx)) {
        return true;
    }
    $adminUsername = auth_lower_text((string)($adminCtx['username'] ?? ''));

    $studentTeacher = auth_lower_text((string)($student['teacher_username'] ?? ''));
    if ($adminUsername !== '' && $studentTeacher !== '' && hash_equals($studentTeacher, $adminUsername)) {
        return true;
    }

    return false;
}

function admin_can_access_student_username(string $username, array $adminCtx): bool
{
    if (admin_is_hauptadmin($adminCtx)) {
        return true;
    }
    $student = find_student_user_by_username($username);
    if (is_array($student)) {
        return admin_can_access_student_record($student, $adminCtx);
    }
    return false;
}

function admin_can_access_course_record(array $course, array $adminCtx): bool
{
    if (admin_is_hauptadmin($adminCtx)) {
        return true;
    }
    $adminUsername = auth_lower_text((string)($adminCtx['username'] ?? ''));
    $courseTeacher = auth_lower_text((string)($course['teacher_username'] ?? ''));
    if ($adminUsername !== '' && $courseTeacher !== '' && hash_equals($courseTeacher, $adminUsername)) {
        return true;
    }
    // Legacy fallback: old course rows can miss teacher_username.
    // In docent mode, allow if at least one course member belongs to this docent scope.
    if ($courseTeacher === '') {
        $members = $course['members'] ?? [];
        if (!is_array($members) || $members === []) {
            return false;
        }
        foreach ($members as $memberUsername) {
            $uname = auth_lower_text((string)$memberUsername);
            if ($uname !== '' && admin_can_access_student_username($uname, $adminCtx)) {
                return true;
            }
        }
        return false;
    }
    return false;
}

function require_member_session_json(): array
{
    start_secure_session();
    enforce_session_timeout_json();
    if (empty($_SESSION['member_authenticated']) || empty($_SESSION['member_username'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Nicht autorisiert. Bitte zuerst als Mitglied anmelden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return [
        'role' => 'member',
        'role_key' => 'member',
        'username' => (string)$_SESSION['member_username'],
        'display_name' => (string)($_SESSION['member_display_name'] ?? ''),
        'email' => (string)($_SESSION['member_email'] ?? ''),
    ];
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

function request_content_type(): string
{
    $raw = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    return auth_lower_text($raw);
}

function request_content_type_is_json(): bool
{
    $ct = request_content_type();
    if ($ct === '') {
        return false;
    }
    return str_starts_with($ct, 'application/json');
}

function require_json_body_or_400(int $maxBytes = 262144): array
{
    $maxBytes = max(1024, $maxBytes);
    $raw = file_get_contents('php://input');
    if (!is_string($raw)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Anfrage.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($raw) > $maxBytes) {
        http_response_code(413);
        echo json_encode(['error' => 'Request ist zu groß.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (trim($raw) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'JSON-Body fehlt.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!request_content_type_is_json()) {
        http_response_code(415);
        echo json_encode(['error' => 'Content-Type muss application/json sein.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $decoded;
}

function validate_password_policy(string $password, string $username = ''): array
{
    $len = mb_strlen($password);
    if ($len < 8 || $len > 128) {
        return ['ok' => false, 'error' => 'Passwort muss 8-128 Zeichen haben.'];
    }
    if (preg_match('/\s/u', $password)) {
        return ['ok' => false, 'error' => 'Passwort darf keine Leerzeichen enthalten.'];
    }
    if (!preg_match('/[A-ZÄÖÜ]/u', $password)) {
        return ['ok' => false, 'error' => 'Passwort muss mindestens einen Großbuchstaben enthalten.'];
    }
    if (!preg_match('/[a-zäöüß]/u', $password)) {
        return ['ok' => false, 'error' => 'Passwort muss mindestens einen Kleinbuchstaben enthalten.'];
    }
    if (!preg_match('/\d/u', $password)) {
        return ['ok' => false, 'error' => 'Passwort muss mindestens eine Zahl enthalten.'];
    }
    $u = auth_lower_text($username);
    if ($u !== '' && mb_strlen($u) >= 3) {
        if (mb_strpos(auth_lower_text($password), $u) !== false) {
            return ['ok' => false, 'error' => 'Passwort darf den Benutzernamen nicht enthalten.'];
        }
    }
    return ['ok' => true, 'error' => ''];
}

function rate_limit_file(string $bucket): string
{
    $safe = preg_replace('/[^a-z0-9._-]+/i', '-', strtolower(trim($bucket)));
    return __DIR__ . '/storage/ratelimit-' . ($safe !== '' ? $safe : 'default') . '.json';
}

function login_guard_file(string $bucket): string
{
    $safe = preg_replace('/[^a-z0-9._-]+/i', '-', strtolower(trim($bucket)));
    return __DIR__ . '/storage/ratelimit-' . ($safe !== '' ? $safe : 'default') . '-v2.json';
}

function login_guard_identifier(string $username): string
{
    $u = auth_lower_text($username);
    if ($u === '') {
        return '';
    }
    return 'u:' . sha1($u);
}

function login_guard_ip_key(string $ip): string
{
    $v = trim($ip);
    if ($v === '') {
        $v = '0.0.0.0';
    }
    return 'ip:' . $v;
}

function login_guard_read_locked($fp): array
{
    rewind($fp);
    $raw = stream_get_contents($fp);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    return $decoded;
}

function login_guard_write_locked($fp, array $payload): bool
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return false;
    }
    rewind($fp);
    if (!ftruncate($fp, 0)) {
        return false;
    }
    if (fwrite($fp, $json) === false) {
        return false;
    }
    return fflush($fp);
}

function login_guard_prune_state(array $data, int $now, int $windowSeconds): array
{
    $cutoff = $now - max(1, $windowSeconds);
    $out = [];
    foreach ($data as $key => $entry) {
        if (!is_string($key) || !is_array($entry)) {
            continue;
        }
        $failsRaw = is_array($entry['fails'] ?? null) ? $entry['fails'] : [];
        $fails = [];
        foreach ($failsRaw as $tsRaw) {
            $ts = (int)$tsRaw;
            if ($ts >= $cutoff) {
                $fails[] = $ts;
            }
        }
        $lockUntil = max(0, (int)($entry['lock_until'] ?? 0));
        if ($fails || $lockUntil > $now) {
            $out[$key] = [
                'fails' => $fails,
                'lock_until' => $lockUntil,
            ];
        }
    }
    return $out;
}

function login_guard_fail_response(int $retryAfterSec): void
{
    $wait = max(1, $retryAfterSec);
    header('Retry-After: ' . (string)$wait);
    http_response_code(429);
    echo json_encode([
        'error' => 'Zu viele Anmeldeversuche. Bitte später erneut versuchen.',
        'retry_after_sec' => $wait,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function check_login_guard_json(string $bucket, string $username, int $maxAttempts, int $windowSeconds, int $lockSeconds): void
{
    $maxAttempts = max(1, $maxAttempts);
    $windowSeconds = max(30, $windowSeconds);
    $lockSeconds = max(60, $lockSeconds);
    $now = time();
    $file = login_guard_file($bucket);
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    $fp = fopen($file, 'c+');
    if ($fp === false) {
        return;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }
    try {
        $state = login_guard_prune_state(login_guard_read_locked($fp), $now, $windowSeconds);
        $keys = [login_guard_ip_key(get_client_ip())];
        $idKey = login_guard_identifier($username);
        if ($idKey !== '') {
            $keys[] = $idKey;
        }
        $retryAfter = 0;
        foreach ($keys as $k) {
            $entry = is_array($state[$k] ?? null) ? $state[$k] : ['fails' => [], 'lock_until' => 0];
            $lockedUntil = max(0, (int)($entry['lock_until'] ?? 0));
            if ($lockedUntil > $now) {
                $retryAfter = max($retryAfter, $lockedUntil - $now);
                continue;
            }
            $fails = is_array($entry['fails'] ?? null) ? $entry['fails'] : [];
            if (count($fails) >= $maxAttempts) {
                $entry['lock_until'] = $now + $lockSeconds;
                $state[$k] = $entry;
                $retryAfter = max($retryAfter, $lockSeconds);
            }
        }
        if (!login_guard_write_locked($fp, $state)) {
            // best effort; continue with request if lock file cannot be written
        }
        if ($retryAfter > 0) {
            login_guard_fail_response($retryAfter);
        }
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function register_login_guard_failure(string $bucket, string $username, int $maxAttempts, int $windowSeconds, int $lockSeconds): void
{
    $maxAttempts = max(1, $maxAttempts);
    $windowSeconds = max(30, $windowSeconds);
    $lockSeconds = max(60, $lockSeconds);
    $now = time();
    $file = login_guard_file($bucket);
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    $fp = fopen($file, 'c+');
    if ($fp === false) {
        return;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }
    try {
        $state = login_guard_prune_state(login_guard_read_locked($fp), $now, $windowSeconds);
        $keys = [login_guard_ip_key(get_client_ip())];
        $idKey = login_guard_identifier($username);
        if ($idKey !== '') {
            $keys[] = $idKey;
        }
        foreach ($keys as $k) {
            $entry = is_array($state[$k] ?? null) ? $state[$k] : ['fails' => [], 'lock_until' => 0];
            $fails = is_array($entry['fails'] ?? null) ? $entry['fails'] : [];
            $fails[] = $now;
            if (count($fails) > ($maxAttempts * 3)) {
                $fails = array_slice($fails, count($fails) - ($maxAttempts * 3));
            }
            $entry['fails'] = $fails;
            if (count($fails) >= $maxAttempts) {
                $entry['lock_until'] = max((int)($entry['lock_until'] ?? 0), $now + $lockSeconds);
            }
            $state[$k] = $entry;
        }
        @login_guard_write_locked($fp, $state);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function clear_login_guard_failures(string $bucket, string $username): void
{
    $file = login_guard_file($bucket);
    if (!is_file($file)) {
        return;
    }
    $fp = fopen($file, 'c+');
    if ($fp === false) {
        return;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }
    try {
        $state = login_guard_read_locked($fp);
        if (!is_array($state)) {
            return;
        }
        unset($state[login_guard_ip_key(get_client_ip())]);
        $idKey = login_guard_identifier($username);
        if ($idKey !== '') {
            unset($state[$idKey]);
        }
        @login_guard_write_locked($fp, $state);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
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
        echo json_encode(['error' => 'Zu viele Versuche. Bitte später erneut versuchen.'], JSON_UNESCAPED_UNICODE);
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
        $actorId = (string)($_SESSION['admin_username'] ?? 'admin');
    } elseif (!empty($_SESSION['student_authenticated']) && !empty($_SESSION['student_username'])) {
        $actorType = 'student';
        $actorId = (string)($_SESSION['student_username'] ?? '');
    } elseif (!empty($_SESSION['member_authenticated']) && !empty($_SESSION['member_username'])) {
        $actorType = 'member';
        $actorId = (string)($_SESSION['member_username'] ?? '');
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
