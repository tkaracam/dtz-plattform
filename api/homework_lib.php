<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function homework_file_path(): string
{
    return __DIR__ . '/storage/homework_assignments.json';
}

function load_homework_assignments(): array
{
    $file = homework_file_path();
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

function write_homework_assignments(array $assignments): bool
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode(array_values($assignments), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return file_put_contents(homework_file_path(), $json . PHP_EOL, LOCK_EX) !== false;
}

function find_course_by_id(string $courseId): ?array
{
    $needle = trim($courseId);
    if ($needle === '') {
        return null;
    }
    foreach (load_courses() as $course) {
        if (!is_array($course)) {
            continue;
        }
        if ((string)($course['course_id'] ?? '') === $needle) {
            return $course;
        }
    }
    return null;
}

function assignment_targets_student(array $assignment, string $username): bool
{
    $uname = mb_strtolower(trim($username));
    if ($uname === '') {
        return false;
    }
    $assignees = is_array($assignment['assignees'] ?? null) ? $assignment['assignees'] : [];
    if (is_array($assignees[$uname] ?? null)) {
        return true;
    }

    // Backward compatibility for older assignment records without assignees map.
    $usernames = is_array($assignment['usernames'] ?? null) ? $assignment['usernames'] : [];
    foreach ($usernames as $row) {
        if (mb_strtolower(trim((string)$row)) === $uname) {
            return true;
        }
    }

    $courseId = trim((string)($assignment['course_id'] ?? ''));
    if ($courseId !== '') {
        $course = find_course_by_id($courseId);
        if (is_array($course)) {
            $members = is_array($course['members'] ?? null) ? $course['members'] : [];
            foreach ($members as $member) {
                if (mb_strtolower(trim((string)$member)) === $uname) {
                    return true;
                }
            }
        }
    }

    return false;
}

function assignment_duration_minutes(array $assignment): int
{
    $minutes = (int)($assignment['duration_minutes'] ?? 0);
    return max(1, min(24 * 60, $minutes));
}

function assignment_availability_ts(array $assignment): int
{
    $startAt = trim((string)($assignment['starts_at'] ?? ''));
    if ($startAt === '') {
        return 0;
    }
    $ts = strtotime($startAt);
    return $ts === false ? 0 : (int)$ts;
}

function assignment_is_active_now(array $assignment, int $nowTs): bool
{
    $status = mb_strtolower(trim((string)($assignment['status'] ?? 'active')));
    if ($status !== 'active') {
        return false;
    }
    $availableFrom = assignment_availability_ts($assignment);
    if ($availableFrom > 0 && $nowTs < $availableFrom) {
        return false;
    }
    return true;
}

function assignment_user_state(array $assignment, string $username): array
{
    $uname = mb_strtolower(trim($username));
    $assignees = is_array($assignment['assignees'] ?? null) ? $assignment['assignees'] : [];
    $state = is_array($assignees[$uname] ?? null) ? $assignees[$uname] : [];

    $startedAt = trim((string)($state['started_at'] ?? ''));
    $deadlineAt = trim((string)($state['deadline_at'] ?? ''));
    $submittedAt = trim((string)($state['submitted_at'] ?? ''));

    $startedTs = $startedAt !== '' ? strtotime($startedAt) : false;
    if ($deadlineAt === '' && $startedTs !== false) {
        $deadlineAt = gmdate('c', (int)$startedTs + assignment_duration_minutes($assignment) * 60);
    }
    $deadlineTs = $deadlineAt !== '' ? strtotime($deadlineAt) : false;

    return [
        'started_at' => $startedAt,
        'deadline_at' => $deadlineAt,
        'submitted_at' => $submittedAt,
        'started_ts' => $startedTs === false ? 0 : (int)$startedTs,
        'deadline_ts' => $deadlineTs === false ? 0 : (int)$deadlineTs,
        'submitted_ts' => $submittedAt !== '' ? ((strtotime($submittedAt) !== false) ? (int)strtotime($submittedAt) : 0) : 0,
        'submission_count' => (int)($state['submission_count'] ?? 0),
        'last_upload_id' => (string)($state['last_upload_id'] ?? ''),
    ];
}

function assignment_visibility_for_admin(array $assignment, array $adminCtx): bool
{
    if (($adminCtx['role'] ?? '') === 'owner') {
        return true;
    }
    $adminUsername = mb_strtolower(trim((string)($adminCtx['username'] ?? '')));
    $teacher = mb_strtolower(trim((string)($assignment['teacher_username'] ?? '')));

    if ($adminUsername !== '' && $teacher !== '' && hash_equals($adminUsername, $teacher)) {
        return true;
    }
    return false;
}

function pick_current_assignment_for_student(array $assignments, string $username, int $nowTs): ?array
{
    $candidates = [];
    foreach ($assignments as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!assignment_targets_student($row, $username)) {
            continue;
        }
        if (!assignment_is_active_now($row, $nowTs)) {
            continue;
        }

        $state = assignment_user_state($row, $username);
        $submitted = $state['submitted_at'] !== '';
        $expired = (!$submitted && $state['deadline_ts'] > 0 && $nowTs >= $state['deadline_ts']);

        $priority = 0;
        if (!$submitted && !$expired && $state['started_at'] !== '') {
            $priority = 3;
        } elseif (!$submitted && !$expired && $state['started_at'] === '') {
            $priority = 2;
        } elseif (!$submitted && $expired) {
            $priority = 1;
        }

        $candidates[] = [
            'assignment' => $row,
            'state' => $state,
            'priority' => $priority,
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    if (!$candidates) {
        return null;
    }

    usort($candidates, static function (array $a, array $b): int {
        if ($a['priority'] !== $b['priority']) {
            return $b['priority'] <=> $a['priority'];
        }
        return strcmp((string)$b['created_at'], (string)$a['created_at']);
    });

    return $candidates[0];
}
