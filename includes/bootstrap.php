<?php

declare(strict_types=1);

session_start();

const APP_ROOT = __DIR__ . '/..';
const DATA_ROOT = APP_ROOT . '/data';

function board_catalog(): array
{
    return [
        'sace' => [
            'id' => 'sace',
            'name' => 'NULIPA-SACE',
            'audience' => 'School of Architecture, Computing and Engineering',
            'tone' => 'Official school-wide academic announcements, administrative notices, institutional advisories, schedules, and shared updates for faculty and students across NULIPA-SACE.',
            'highlights' => ['Official notices', 'Academic advisories', 'School-wide updates'],
        ],
        'architecture' => [
            'id' => 'architecture',
            'name' => 'Architecture',
            'audience' => 'NU LIPA School of Architecture',
            'tone' => 'Academic notices for Architecture faculty and students, including class schedules, studio advisories, consultation schedules, submission deadlines, and departmental announcements.',
            'highlights' => ['Class advisories', 'Studio schedules', 'Department notices'],
        ],
        'computer-science' => [
            'id' => 'computer-science',
            'name' => 'BS Computer Science',
            'audience' => 'NU LIPA School of Computing',
            'tone' => 'Academic notices for BS Computer Science faculty and students, including laboratory schedules, research advisories, class announcements, examinations, and capstone coordination.',
            'highlights' => ['Laboratory advisories', 'Academic schedules', 'Research notices'],
        ],
        'information-technology' => [
            'id' => 'information-technology',
            'name' => 'BS Information Technology',
            'audience' => 'NU LIPA School of Computing',
            'tone' => 'Academic notices for BS Information Technology faculty and students, including class advisories, laboratory schedules, practicum updates, examinations, and departmental announcements.',
            'highlights' => ['Class advisories', 'Laboratory schedules', 'Practicum notices'],
        ],
        'engineering' => [
            'id' => 'engineering',
            'name' => 'Engineering',
            'audience' => 'NU LIPA School of Engineering',
            'tone' => 'Academic notices for Engineering faculty and students, including laboratory access schedules, safety advisories, class announcements, project deadlines, and departmental memoranda.',
            'highlights' => ['Safety advisories', 'Laboratory access', 'Department memoranda'],
        ],
        'mma' => [
            'id' => 'mma',
            'name' => 'Multimedia Arts',
            'audience' => 'NU LIPA SACE',
            'tone' => 'Academic notices for Multimedia Arts faculty and students, including class advisories, production schedules, consultation notices, portfolio requirements, and departmental announcements.',
            'highlights' => ['Production schedules', 'Consultation notices', 'Department updates'],
        ],
        'cpe' => [
            'id' => 'cpe',
            'name' => 'Computer Engineering',
            'audience' => 'NU LIPA SACE',
            'tone' => 'Academic notices for Computer Engineering faculty and students, including laboratory schedules, technical consultations, project requirements, examinations, and departmental advisories.',
            'highlights' => ['Technical advisories', 'Laboratory schedules', 'Project requirements'],
        ],
    ];
}

function notice_categories(): array
{
    return [
        'Announcement',
        'Academic Calendar',
        'Class Advisory',
        'Examination Schedule',
        'Enrollment',
        'Deadline',
        'Faculty Advisory',
        'Student Services',
        'Scholarship',
        'Internship',
        'Career Opportunity',
        'Research',
        'Capstone',
        'Workshop',
        'Seminar',
        'Competition',
        'Exhibit',
        'Laboratory',
        'Facilities',
        'Safety',
        'Event',
    ];
}

function notices_path(): string
{
    return DATA_ROOT . '/notices.json';
}

function users_path(): string
{
    return DATA_ROOT . '/users.json';
}

function read_json_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $content = file_get_contents($path);
    if ($content === false || $content === '') {
        return [];
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function read_json_file_locked(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open JSON file.');
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException('Unable to acquire read lock.');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    if ($content === false || $content === '') {
        return [];
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function write_json_file(string $path, array $payload): void
{
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Unable to encode JSON payload.');
    }

    file_put_contents($path, $encoded, LOCK_EX);
}

function all_users(): array
{
    return read_json_file(users_path());
}

function normalize_notice_record(array $notice): array
{
    $notice['created_by'] = (string) ($notice['created_by'] ?? 'system');
    $notice['created_by_name'] = (string) ($notice['created_by_name'] ?? 'System Seeded Notice');
    $notice['pinned'] = (bool) ($notice['pinned'] ?? false);
    $notice['created_at'] = (string) ($notice['created_at'] ?? '');
    $notice['updated_at'] = (string) ($notice['updated_at'] ?? '');
    $notice['visible_from'] = (string) ($notice['visible_from'] ?? '0001-01-01');
    $notice['visible_until'] = (string) ($notice['visible_until'] ?? '9999-12-31');
    $rawTags = $notice['tags'] ?? ($notice['tag'] ?? []);
    if (is_string($rawTags)) {
        $rawTags = array_filter(array_map('trim', explode(',', $rawTags)));
    }
    if (!is_array($rawTags)) {
        $rawTags = [];
    }
    $notice['tags'] = array_values(array_unique(array_filter(array_map(
        static fn ($tag): string => trim((string) $tag),
        $rawTags
    ))));
    $notice['tag'] = implode(', ', $notice['tags']);

    return $notice;
}

function all_notices(): array
{
    $notices = read_json_file_locked(notices_path());

    return array_map('normalize_notice_record', $notices);
}

function mutate_notices(callable $mutator): array
{
    $path = notices_path();
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open notice storage.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to acquire write lock.');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        $decoded = ($content === false || $content === '') ? [] : json_decode($content, true);
        $notices = is_array($decoded) ? array_map('normalize_notice_record', $decoded) : [];

        $updatedNotices = $mutator($notices);
        if (!is_array($updatedNotices)) {
            throw new RuntimeException('Notice mutation failed.');
        }

        $encoded = json_encode($updatedNotices, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode notice storage.');
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $encoded);
        fflush($handle);
        flock($handle, LOCK_UN);

        return array_map('normalize_notice_record', $updatedNotices);
    } finally {
        fclose($handle);
    }
}

function today_ymd(): string
{
    return date('Y-m-d');
}

function is_notice_visible(array $notice, ?string $today = null): bool
{
    $today ??= today_ymd();
    $visibleFrom = (string) ($notice['visible_from'] ?? '0001-01-01');
    $visibleUntil = (string) ($notice['visible_until'] ?? '9999-12-31');

    return $visibleFrom <= $today && $today <= $visibleUntil;
}

function notice_scope_status(array $notice, ?string $today = null): string
{
    $today ??= today_ymd();
    $visibleFrom = (string) ($notice['visible_from'] ?? '0001-01-01');
    $visibleUntil = (string) ($notice['visible_until'] ?? '9999-12-31');

    if ($today < $visibleFrom) {
        return 'scheduled';
    }

    if ($today > $visibleUntil) {
        return 'expired';
    }

    return 'active';
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function login_user(array $user): void
{
    $_SESSION['user'] = [
        'username' => $user['username'],
        'name' => $user['name'],
        'role' => $user['role'],
        'board_ids' => $user['board_ids'],
    ];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /NUSACE-Bulletin/admin/login.php');
        exit;
    }
}

function can_manage_board(array $user, string $boardId): bool
{
    if ($user['role'] === 'dean') {
        return true;
    }

    return in_array($boardId, $user['board_ids'], true);
}

function can_edit_notice(array $user, array $notice): bool
{
    return ($notice['created_by'] ?? '') === ($user['username'] ?? '');
}

function accessible_boards(array $user): array
{
    $catalog = board_catalog();

    if ($user['role'] === 'dean') {
        return $catalog;
    }

    return array_filter(
        $catalog,
        static fn (array $board): bool => in_array($board['id'], $user['board_ids'], true)
    );
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('Invalid session token.');
    }
}

function find_notice_by_id(array $notices, string $id): ?array
{
    foreach ($notices as $notice) {
        if (($notice['id'] ?? '') === $id) {
            return $notice;
        }
    }

    return null;
}

function sort_notices(array &$notices): void
{
    usort(
        $notices,
        static function (array $left, array $right): int {
            $leftPinned = (bool) ($left['pinned'] ?? false);
            $rightPinned = (bool) ($right['pinned'] ?? false);

            if ($leftPinned !== $rightPinned) {
                return $rightPinned <=> $leftPinned;
            }

            $dateComparison = strcmp((string) ($right['date'] ?? ''), (string) ($left['date'] ?? ''));
            if ($dateComparison !== 0) {
                return $dateComparison;
            }

            return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
        }
    );
}

function group_boards_for_public(): array
{
    $catalog = board_catalog();
    $notices = all_notices();
    $today = today_ymd();

    sort_notices($notices);

    foreach ($catalog as $boardId => $board) {
        $catalog[$boardId]['notices'] = [];
    }

    foreach ($notices as $notice) {
        if (!is_notice_visible($notice, $today)) {
            continue;
        }

        $boardId = $notice['board_id'] ?? '';
        if (!isset($catalog[$boardId])) {
            continue;
        }

        $catalog[$boardId]['notices'][] = [
            'id' => $notice['id'],
            'category' => $notice['category'],
            'audience' => $notice['audience'],
            'title' => $notice['title'],
            'date' => $notice['date'],
            'text' => $notice['text'],
            'tag' => $notice['tag'],
            'tags' => $notice['tags'],
            'pinned' => (bool) ($notice['pinned'] ?? false),
            'updated_at' => (string) ($notice['updated_at'] ?? ''),
            'visible_from' => (string) ($notice['visible_from'] ?? ''),
            'visible_until' => (string) ($notice['visible_until'] ?? ''),
        ];
    }

    return array_values($catalog);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
