<?php

declare(strict_types=1);

session_start();

const APP_ROOT = __DIR__ . '/..';
const DATA_ROOT = APP_ROOT . '/data';
const ATTACHMENT_ROOT = APP_ROOT . '/uploads/attachments';
const MAX_ATTACHMENT_BYTES = 10485760;

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

function reactions_path(): string
{
    return DATA_ROOT . '/reactions.json';
}

function attachment_public_path(string $filename): string
{
    return 'uploads/attachments/' . ltrim(str_replace('\\', '/', $filename), '/');
}

function attachment_allowed_types(): array
{
    return [
        'application/pdf' => ['extension' => 'pdf', 'kind' => 'pdf'],
        'image/jpeg' => ['extension' => 'jpg', 'kind' => 'image'],
        'image/png' => ['extension' => 'png', 'kind' => 'image'],
        'image/gif' => ['extension' => 'gif', 'kind' => 'image'],
        'image/webp' => ['extension' => 'webp', 'kind' => 'image'],
    ];
}

function ensure_attachment_root(): void
{
    if (is_dir(ATTACHMENT_ROOT)) {
        return;
    }

    if (!mkdir(ATTACHMENT_ROOT, 0775, true) && !is_dir(ATTACHMENT_ROOT)) {
        throw new RuntimeException('Unable to create the attachment storage directory.');
    }
}

function sanitize_attachment_name(string $name): string
{
    $baseName = trim(basename($name));
    if ($baseName === '') {
        return 'attachment';
    }

    $sanitized = preg_replace('/[^A-Za-z0-9._ -]/', '', $baseName);
    $sanitized = preg_replace('/\s+/', ' ', (string) $sanitized);

    return $sanitized !== '' ? $sanitized : 'attachment';
}

function normalize_attachment_record(mixed $attachment): ?array
{
    if (!is_array($attachment) || empty($attachment['path'])) {
        return null;
    }

    return [
        'path' => str_replace('\\', '/', (string) $attachment['path']),
        'name' => sanitize_attachment_name((string) ($attachment['name'] ?? 'attachment')),
        'mime' => (string) ($attachment['mime'] ?? ''),
        'kind' => (string) ($attachment['kind'] ?? ''),
        'size' => (int) ($attachment['size'] ?? 0),
    ];
}

function store_uploaded_attachment(array $file): array
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('No attachment was uploaded.');
    }

    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The attachment upload failed. Please try again.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('The uploaded attachment is empty.');
    }

    if ($size > MAX_ATTACHMENT_BYTES) {
        throw new RuntimeException('Attachments must be 10 MB or smaller.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid uploaded attachment.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = attachment_allowed_types();
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only one PDF or one image file may be attached to a notice.');
    }

    ensure_attachment_root();

    $config = $allowed[$mime];
    $storedName = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $config['extension'];
    $targetPath = ATTACHMENT_ROOT . '/' . $storedName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Unable to save the uploaded attachment.');
    }

    return [
        'path' => attachment_public_path($storedName),
        'name' => sanitize_attachment_name((string) ($file['name'] ?? 'attachment')),
        'mime' => $mime,
        'kind' => $config['kind'],
        'size' => $size,
    ];
}

function delete_attachment_file(?array $attachment): void
{
    $record = normalize_attachment_record($attachment);
    if ($record === null) {
        return;
    }

    $relativePath = ltrim((string) $record['path'], '/');
    $absolutePath = APP_ROOT . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $resolvedRoot = realpath(ATTACHMENT_ROOT);
    $resolvedFile = realpath($absolutePath);

    if ($resolvedRoot === false || $resolvedFile === false) {
        return;
    }

    if (strpos($resolvedFile, $resolvedRoot) !== 0) {
        return;
    }

    if (is_file($resolvedFile)) {
        unlink($resolvedFile);
    }
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
    return read_json_file_locked(users_path());
}

function all_reactions(): array
{
    return read_json_file_locked(reactions_path());
}

function mutate_users(callable $mutator): array
{
    $path = users_path();
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open user storage.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to acquire user write lock.');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        $decoded = ($content === false || $content === '') ? [] : json_decode($content, true);
        $users = is_array($decoded) ? $decoded : [];

        $updatedUsers = $mutator($users);
        if (!is_array($updatedUsers)) {
            throw new RuntimeException('User mutation failed.');
        }

        $encoded = json_encode($updatedUsers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode user storage.');
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $encoded);
        fflush($handle);
        flock($handle, LOCK_UN);

        return $updatedUsers;
    } finally {
        fclose($handle);
    }
}

function mutate_reactions(callable $mutator): array
{
    $path = reactions_path();
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open reaction storage.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to acquire reaction write lock.');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        $decoded = ($content === false || $content === '') ? [] : json_decode($content, true);
        $reactions = is_array($decoded) ? $decoded : [];

        $updatedReactions = $mutator($reactions);
        if (!is_array($updatedReactions)) {
            throw new RuntimeException('Reaction mutation failed.');
        }

        $encoded = json_encode($updatedReactions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode reaction storage.');
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $encoded);
        fflush($handle);
        flock($handle, LOCK_UN);

        return $updatedReactions;
    } finally {
        fclose($handle);
    }
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
    $notice['attachment'] = normalize_attachment_record($notice['attachment'] ?? null);

    return $notice;
}

function normalize_client_id(string $clientId): string
{
    $sanitized = preg_replace('/[^A-Za-z0-9_-]/', '', $clientId);
    return substr((string) $sanitized, 0, 80);
}

function reaction_types(): array
{
    return ['like', 'heart'];
}

function reaction_summary_for_notice(string $noticeId, ?string $clientId = null): array
{
    $summary = [
        'like' => ['count' => 0, 'reacted' => false],
        'heart' => ['count' => 0, 'reacted' => false],
    ];

    $clientId = $clientId !== null ? normalize_client_id($clientId) : null;
    $all = all_reactions();
    $noticeReactions = $all[$noticeId] ?? [];

    foreach (reaction_types() as $type) {
        $clients = $noticeReactions[$type] ?? [];
        if (!is_array($clients)) {
            $clients = [];
        }

        $normalizedClients = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => normalize_client_id((string) $value),
            $clients
        ))));

        $summary[$type]['count'] = count($normalizedClients);
        $summary[$type]['reacted'] = $clientId !== null && in_array($clientId, $normalizedClients, true);
    }

    return $summary;
}

function toggle_notice_reaction(string $noticeId, string $reactionType, string $clientId): array
{
    $clientId = normalize_client_id($clientId);
    if ($clientId === '') {
        throw new RuntimeException('Invalid client identifier.');
    }

    if (!in_array($reactionType, reaction_types(), true)) {
        throw new RuntimeException('Invalid reaction type.');
    }

    mutate_reactions(static function (array $reactions) use ($noticeId, $reactionType, $clientId): array {
        $noticeReactions = $reactions[$noticeId] ?? [];
        $clients = $noticeReactions[$reactionType] ?? [];
        if (!is_array($clients)) {
            $clients = [];
        }

        $clients = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => normalize_client_id((string) $value),
            $clients
        ))));

        $existingIndex = array_search($clientId, $clients, true);
        if ($existingIndex === false) {
            $clients[] = $clientId;
        } else {
            unset($clients[$existingIndex]);
            $clients = array_values($clients);
        }

        $noticeReactions[$reactionType] = $clients;
        $reactions[$noticeId] = $noticeReactions;
        return $reactions;
    });

    return reaction_summary_for_notice($noticeId, $clientId);
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

function shift_ymd(string $date, int $days): string
{
    $timestamp = strtotime($date . ' 00:00:00');
    if ($timestamp === false) {
        return $date;
    }

    return date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days', $timestamp));
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

function is_priority_notice_visible(array $notice, ?string $today = null): bool
{
    $today ??= today_ymd();
    $visibleFrom = (string) ($notice['visible_from'] ?? '0001-01-01');
    $visibleUntil = (string) ($notice['visible_until'] ?? '9999-12-31');
    $priorityStart = shift_ymd($visibleFrom, -7);

    return $priorityStart <= $today && $today <= $visibleUntil;
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
        header('Location: login.php');
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

function find_user_by_username(array $users, string $username): ?array
{
    foreach ($users as $user) {
        if (($user['username'] ?? '') === $username) {
            return $user;
        }
    }

    return null;
}

function update_notice_owner_references(string $fromUsername, string $toUsername, string $toName): void
{
    if ($fromUsername === $toUsername) {
        mutate_notices(static function (array $notices) use ($toUsername, $toName): array {
            foreach ($notices as $index => $notice) {
                if (($notice['created_by'] ?? '') === $toUsername) {
                    $notices[$index]['created_by_name'] = $toName;
                }
            }
            return $notices;
        });
        return;
    }

    mutate_notices(static function (array $notices) use ($fromUsername, $toUsername, $toName): array {
        foreach ($notices as $index => $notice) {
            if (($notice['created_by'] ?? '') === $fromUsername) {
                $notices[$index]['created_by'] = $toUsername;
                $notices[$index]['created_by_name'] = $toName;
            }
        }
        return $notices;
    });
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

function group_boards_for_public(?string $clientId = null): array
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
            'created_at' => (string) ($notice['created_at'] ?? ''),
            'text' => $notice['text'],
            'tag' => $notice['tag'],
            'tags' => $notice['tags'],
            'pinned' => (bool) ($notice['pinned'] ?? false),
            'updated_at' => (string) ($notice['updated_at'] ?? ''),
            'visible_from' => (string) ($notice['visible_from'] ?? ''),
            'visible_until' => (string) ($notice['visible_until'] ?? ''),
            'attachment' => $notice['attachment'],
            'reactions' => reaction_summary_for_notice((string) $notice['id'], $clientId),
        ];
    }

    return array_values($catalog);
}

function priority_notices_for_public(?string $clientId = null): array
{
    $catalog = board_catalog();
    $notices = all_notices();
    $today = today_ymd();

    $priority = array_values(array_filter(
        $notices,
        static fn (array $notice): bool => is_priority_notice_visible($notice, $today)
    ));

    sort_notices($priority);

    return array_map(
        static function (array $notice) use ($catalog): array {
            $boardId = (string) ($notice['board_id'] ?? '');
            return [
                'id' => $notice['id'],
                'board_id' => $boardId,
                'board_name' => $catalog[$boardId]['name'] ?? $boardId,
                'category' => $notice['category'],
                'audience' => $notice['audience'],
                'title' => $notice['title'],
                'date' => $notice['date'],
                'created_at' => (string) ($notice['created_at'] ?? ''),
                'visible_from' => $notice['visible_from'],
                'visible_until' => $notice['visible_until'],
                'text' => $notice['text'],
                'tag' => $notice['tag'],
                'tags' => $notice['tags'],
                'pinned' => (bool) ($notice['pinned'] ?? false),
                'updated_at' => (string) ($notice['updated_at'] ?? ''),
                'attachment' => $notice['attachment'],
                'reactions' => reaction_summary_for_notice((string) $notice['id'], $clientId),
            ];
        },
        $priority
    );
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
