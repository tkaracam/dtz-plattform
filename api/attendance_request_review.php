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
require_admin_session_json();

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON wurde gesendet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$requestId = trim((string)($body['request_id'] ?? ''));
$decision = trim((string)($body['decision'] ?? '')); // approve|reject
$reviewNote = trim((string)($body['review_note'] ?? ''));

if ($requestId === '' || !in_array($decision, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'request_id ve decision gereklidir.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = __DIR__ . '/storage/attendance_requests.json';
$requests = [];
if (is_file($file)) {
    $rawReq = file_get_contents($file);
    $tmp = is_string($rawReq) ? json_decode($rawReq, true) : null;
    if (is_array($tmp)) $requests = $tmp;
}

$found = false;
$sessionIdToUnlock = '';
foreach ($requests as &$r) {
    if (!is_array($r)) continue;
    if ((string)($r['request_id'] ?? '') !== $requestId) continue;
    $r['status'] = $decision === 'approve' ? 'approved' : 'rejected';
    $r['review_note'] = $reviewNote;
    $r['updated_at'] = gmdate('c');
    $sessionIdToUnlock = (string)($r['session_id'] ?? '');
    $found = true;
    break;
}
unset($r);

if (!$found) {
    http_response_code(404);
    echo json_encode(['error' => 'Talep bulunamadı.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($decision === 'approve' && $sessionIdToUnlock !== '') {
    $attFile = __DIR__ . '/storage/attendance_sessions.json';
    if (is_file($attFile)) {
        $rawAtt = file_get_contents($attFile);
        $sessions = is_string($rawAtt) ? json_decode($rawAtt, true) : null;
        if (is_array($sessions)) {
            foreach ($sessions as &$s) {
                if (!is_array($s)) continue;
                if ((string)($s['session_id'] ?? '') !== $sessionIdToUnlock) continue;
                $s['locked'] = false;
                $s['updated_at'] = gmdate('c');
                $s['unlock_reason'] = 'approved_request:' . $requestId;
                break;
            }
            unset($s);
            $attJson = json_encode(array_values($sessions), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if (is_string($attJson)) {
                file_put_contents($attFile, $attJson . PHP_EOL, LOCK_EX);
            }
        }
    }
}

$json = json_encode(array_values($requests), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if (!is_string($json) || file_put_contents($file, $json . PHP_EOL, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Talep review kaydedilemedi.'], JSON_UNESCAPED_UNICODE);
    exit;
}

append_audit_log('attendance_request_review', [
    'request_id' => $requestId,
    'decision' => $decision,
    'session_id' => $sessionIdToUnlock,
]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
