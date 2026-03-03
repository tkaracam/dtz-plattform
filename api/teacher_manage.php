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
$owner = require_owner_session_json();

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($body['action'] ?? ''));
$teachers = load_teacher_users();

if ($action === 'create') {
    $username = mb_strtolower(trim((string)($body['username'] ?? '')));
    $displayName = trim((string)($body['display_name'] ?? ''));
    $bamfCode = normalize_bamf_code((string)($body['bamf_code'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
        http_response_code(400);
        echo json_encode(['error' => 'Benutzername muss 3-32 Zeichen haben (a-z, 0-9, ., _, -).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($username === (string)($owner['username'] ?? '')) {
        http_response_code(409);
        echo json_encode(['error' => 'Dieser Benutzername ist fuer den Haupt-Admin reserviert.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($bamfCode === '') {
        http_response_code(400);
        echo json_encode(['error' => 'bamf_code muss im Format bamf233 sein.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (mb_strlen($password) < 6 || mb_strlen($password) > 128) {
        http_response_code(400);
        echo json_encode(['error' => 'Passwort muss 6-128 Zeichen haben.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    foreach ($teachers as $teacher) {
        if (!is_array($teacher)) {
            continue;
        }
        $taken = mb_strtolower(trim((string)($teacher['username'] ?? '')));
        if ($taken === $username) {
            http_response_code(409);
            echo json_encode(['error' => 'Benutzername existiert bereits.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $takenCode = normalize_bamf_code((string)($teacher['bamf_code'] ?? ''));
        if ($takenCode !== '' && hash_equals($takenCode, $bamfCode)) {
            http_response_code(409);
            echo json_encode(['error' => 'Dieser BAMF-Code ist bereits einer anderen Lehrkraft zugeordnet.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    $teachers[] = [
        'username' => $username,
        'display_name' => $displayName,
        'bamf_code' => $bamfCode,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'active' => true,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];
} else {
    $username = mb_strtolower(trim((string)($body['username'] ?? '')));
    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungueltiger Benutzername.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $foundIndex = -1;
    foreach ($teachers as $i => $teacher) {
        if (!is_array($teacher)) {
            continue;
        }
        if (mb_strtolower(trim((string)($teacher['username'] ?? ''))) === $username) {
            $foundIndex = $i;
            break;
        }
    }
    if ($foundIndex < 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Lehrkraft nicht gefunden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'set_active') {
        $teachers[$foundIndex]['active'] = !empty($body['active']);
        $teachers[$foundIndex]['updated_at'] = gmdate('c');
    } elseif ($action === 'reset_password') {
        $newPassword = (string)($body['new_password'] ?? '');
        if (mb_strlen($newPassword) < 6 || mb_strlen($newPassword) > 128) {
            http_response_code(400);
            echo json_encode(['error' => 'Passwort muss 6-128 Zeichen haben.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $teachers[$foundIndex]['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $teachers[$foundIndex]['updated_at'] = gmdate('c');
    } elseif ($action === 'update_profile') {
        $displayName = trim((string)($body['display_name'] ?? ''));
        $bamfCode = normalize_bamf_code((string)($body['bamf_code'] ?? ''));
        if ($bamfCode === '') {
            http_response_code(400);
            echo json_encode(['error' => 'bamf_code muss im Format bamf233 sein.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        foreach ($teachers as $i => $teacher) {
            if ($i === $foundIndex || !is_array($teacher)) {
                continue;
            }
            $takenCode = normalize_bamf_code((string)($teacher['bamf_code'] ?? ''));
            if ($takenCode !== '' && hash_equals($takenCode, $bamfCode)) {
                http_response_code(409);
                echo json_encode(['error' => 'Dieser BAMF-Code ist bereits einer anderen Lehrkraft zugeordnet.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        $teachers[$foundIndex]['display_name'] = $displayName;
        $teachers[$foundIndex]['bamf_code'] = $bamfCode;
        $teachers[$foundIndex]['updated_at'] = gmdate('c');
    } elseif ($action === 'delete') {
        array_splice($teachers, $foundIndex, 1);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Ungueltige Aktion.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!write_teacher_users($teachers)) {
    http_response_code(500);
    echo json_encode(['error' => 'Lehrkraftdaten konnten nicht gespeichert werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('teacher_manage', [
    'action' => $action,
    'username' => (string)($username ?? ''),
]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
