<?php

declare(strict_types=1);

session_start();

$dbConfigOverride = __DIR__ . '/db-config.local.php';
if (is_file($dbConfigOverride)) {
    require_once $dbConfigOverride;
}

$mailerConfigOverride = __DIR__ . '/mailer-config.local.php';
if (is_file($mailerConfigOverride)) {
    require_once $mailerConfigOverride;
}

const APP_ROOT = __DIR__ . '/..';
const DATA_ROOT = APP_ROOT . '/data';
const ATTACHMENT_ROOT = APP_ROOT . '/uploads/attachments';
const MAX_ATTACHMENT_BYTES = 10485760;
const PUBLISHED_CALENDAR_HTML_URL = 'https://outlook.office365.com/owa/calendar/2c19d868a4d34204863b68a58eaf736b@nu-lipa.edu.ph/f15f9b81b2db4fa78ac383d6519ad28d12429557842574329440/calendar.html';
const PUBLISHED_CALENDAR_ICS_URL = 'https://outlook.office365.com/owa/calendar/2c19d868a4d34204863b68a58eaf736b@nu-lipa.edu.ph/f15f9b81b2db4fa78ac383d6519ad28d12429557842574329440/calendar.ics';

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

function published_calendar_html_url(): string
{
    return PUBLISHED_CALENDAR_HTML_URL;
}

function published_calendar_ics_url(): string
{
    return PUBLISHED_CALENDAR_ICS_URL;
}

function manual_calendar_event_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Manila');
}

function is_missing_table_exception(Throwable $exception, string $tableName): bool
{
    $message = $exception->getMessage();

    return str_contains($message, "Table '")
        && str_contains($message, '.' . $tableName . "'")
        && str_contains($message, "doesn't exist");
}

function normalize_manual_calendar_event_record(array $event): array
{
    $boardId = trim((string) ($event['board_id'] ?? ''));
    $eventDate = trim((string) ($event['event_date'] ?? ''));
    $startTime = substr(trim((string) ($event['start_time'] ?? '')), 0, 5);
    $endTime = substr(trim((string) ($event['end_time'] ?? '')), 0, 5);

    return [
        'id' => trim((string) ($event['id'] ?? '')),
        'board_id' => $boardId,
        'title' => trim((string) ($event['title'] ?? '')),
        'event_date' => $eventDate,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'created_by' => trim((string) ($event['created_by'] ?? '')),
        'created_by_name' => trim((string) ($event['created_by_name'] ?? '')),
        'created_at' => mysql_datetime_to_iso8601((string) ($event['created_at'] ?? '')),
        'updated_at' => mysql_datetime_to_iso8601((string) ($event['updated_at'] ?? '')),
    ];
}

function all_manual_calendar_events(?PDO $pdo = null): array
{
    $pdo ??= database();
    try {
        $statement = $pdo->query('
            SELECT id, board_id, title, event_date, start_time, end_time, created_by, created_by_name, created_at, updated_at
            FROM manual_calendar_events
            ORDER BY event_date ASC, start_time ASC, created_at ASC
        ');
    } catch (Throwable $exception) {
        if (is_missing_table_exception($exception, 'manual_calendar_events')) {
            return [];
        }

        throw $exception;
    }

    return array_map('normalize_manual_calendar_event_record', $statement->fetchAll());
}

function find_manual_calendar_event_by_id(array $events, string $id): ?array
{
    foreach ($events as $event) {
        if (($event['id'] ?? '') === $id) {
            return $event;
        }
    }

    return null;
}

function persist_manual_calendar_events(array $events, ?PDO $pdo = null): void
{
    $pdo ??= database();
    $pdo->exec('DELETE FROM manual_calendar_events');

    $statement = $pdo->prepare('
        INSERT INTO manual_calendar_events (
            id, board_id, title, event_date, start_time, end_time, created_by, created_by_name, created_at, updated_at
        ) VALUES (
            :id, :board_id, :title, :event_date, :start_time, :end_time, :created_by, :created_by_name, :created_at, :updated_at
        )
    ');

    foreach ($events as $event) {
        $event = normalize_manual_calendar_event_record($event);
        if (
            $event['id'] === ''
            || $event['board_id'] === ''
            || $event['title'] === ''
            || $event['event_date'] === ''
            || $event['start_time'] === ''
            || $event['end_time'] === ''
            || $event['created_by'] === ''
            || $event['created_by_name'] === ''
        ) {
            throw new RuntimeException('Manual calendar events must include board, title, date, time, and owner details.');
        }

        $statement->execute([
            ':id' => $event['id'],
            ':board_id' => $event['board_id'],
            ':title' => $event['title'],
            ':event_date' => $event['event_date'],
            ':start_time' => $event['start_time'],
            ':end_time' => $event['end_time'],
            ':created_by' => $event['created_by'],
            ':created_by_name' => $event['created_by_name'],
            ':created_at' => iso8601_to_mysql_datetime($event['created_at']) ?? gmdate('Y-m-d H:i:s'),
            ':updated_at' => iso8601_to_mysql_datetime($event['updated_at']) ?? gmdate('Y-m-d H:i:s'),
        ]);
    }
}

function mutate_manual_calendar_events(callable $mutator): array
{
    return database_transaction(static function (PDO $pdo) use ($mutator): array {
        $events = all_manual_calendar_events($pdo);
        $updatedEvents = $mutator($events);
        if (!is_array($updatedEvents)) {
            throw new RuntimeException('Manual calendar event mutation failed.');
        }

        persist_manual_calendar_events($updatedEvents, $pdo);

        return all_manual_calendar_events($pdo);
    });
}

function can_edit_manual_calendar_event(array $user, array $event): bool
{
    return ($event['created_by'] ?? '') === ($user['username'] ?? '');
}

function manual_calendar_event_public_record(array $event): array
{
    $normalized = normalize_manual_calendar_event_record($event);
    $timezone = manual_calendar_event_timezone();
    $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $normalized['event_date'] . ' ' . $normalized['start_time'], $timezone);
    $end = DateTimeImmutable::createFromFormat('Y-m-d H:i', $normalized['event_date'] . ' ' . $normalized['end_time'], $timezone);

    if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
        throw new RuntimeException('Manual calendar event has an invalid date or time.');
    }

    return [
        'id' => $normalized['id'],
        'title' => $normalized['title'],
        'description' => '',
        'location' => '',
        'url' => '',
        'is_all_day' => false,
        'starts_at' => $start->format(DATE_ATOM),
        'ends_at' => $end->format(DATE_ATOM),
        'month_key' => $start->format('Y-m'),
        'sort_key' => $start->format('YmdHis'),
        'source' => 'manual',
        'source_label' => 'Manual Calendar Entry',
        'board_id' => $normalized['board_id'],
        'board_name' => board_catalog()[$normalized['board_id']]['name'] ?? $normalized['board_id'],
    ];
}

function manual_calendar_events_for_public(): array
{
    $events = [];

    foreach (all_manual_calendar_events() as $event) {
        try {
            $events[] = manual_calendar_event_public_record($event);
        } catch (RuntimeException $exception) {
            continue;
        }
    }

    usort($events, static fn (array $left, array $right): int => strcmp((string) $left['sort_key'], (string) $right['sort_key']));

    return $events;
}

function normalize_news_link_record(array $newsLink): array
{
    return [
        'id' => (int) ($newsLink['id'] ?? 0),
        'title' => trim((string) ($newsLink['title'] ?? '')),
        'summary' => trim((string) ($newsLink['summary'] ?? '')),
        'facebook_url' => trim((string) ($newsLink['facebook_url'] ?? '')),
        'image_url' => trim((string) ($newsLink['image_url'] ?? '')),
        'created_by' => trim((string) ($newsLink['created_by'] ?? '')),
        'created_at' => mysql_datetime_to_iso8601((string) ($newsLink['created_at'] ?? '')),
        'updated_at' => mysql_datetime_to_iso8601((string) ($newsLink['updated_at'] ?? '')),
    ];
}

function all_news_links(?PDO $pdo = null): array
{
    $pdo ??= database();

    try {
        $statement = $pdo->query('
            SELECT id, title, summary, facebook_url, image_url, created_by, created_at, updated_at
            FROM news_links
            ORDER BY created_at DESC, id DESC
        ');
    } catch (Throwable $exception) {
        if (is_missing_table_exception($exception, 'news_links')) {
            return [];
        }

        throw $exception;
    }

    return array_map('normalize_news_link_record', $statement->fetchAll());
}

function find_news_link_by_id(array $newsLinks, int $id): ?array
{
    foreach ($newsLinks as $newsLink) {
        if ((int) ($newsLink['id'] ?? 0) === $id) {
            return $newsLink;
        }
    }

    return null;
}

function save_news_link(array $newsLink): int
{
    return database_transaction(static function (PDO $pdo) use ($newsLink): int {
        $record = normalize_news_link_record($newsLink);
        $now = gmdate('Y-m-d H:i:s');

        if ($record['id'] > 0) {
            $statement = $pdo->prepare('
                UPDATE news_links
                SET title = :title,
                    summary = :summary,
                    facebook_url = :facebook_url,
                    image_url = :image_url,
                    updated_at = :updated_at
                WHERE id = :id
            ');
            $statement->execute([
                ':id' => $record['id'],
                ':title' => $record['title'],
                ':summary' => $record['summary'],
                ':facebook_url' => $record['facebook_url'],
                ':image_url' => $record['image_url'] !== '' ? $record['image_url'] : null,
                ':updated_at' => $now,
            ]);

            if ($statement->rowCount() === 0 && find_news_link_by_id(all_news_links($pdo), $record['id']) === null) {
                throw new RuntimeException('News link not found.');
            }

            return $record['id'];
        }

        $statement = $pdo->prepare('
            INSERT INTO news_links (
                title, summary, facebook_url, image_url, created_by, created_at, updated_at
            ) VALUES (
                :title, :summary, :facebook_url, :image_url, :created_by, :created_at, :updated_at
            )
        ');
        $statement->execute([
            ':title' => $record['title'],
            ':summary' => $record['summary'],
            ':facebook_url' => $record['facebook_url'],
            ':image_url' => $record['image_url'] !== '' ? $record['image_url'] : null,
            ':created_by' => $record['created_by'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    });
}

function delete_news_link(int $id): void
{
    database_transaction(static function (PDO $pdo) use ($id): void {
        $statement = $pdo->prepare('DELETE FROM news_links WHERE id = :id');
        $statement->execute([':id' => $id]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('News link not found.');
        }
    });
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

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function database_settings(): array
{
    return [
        'host' => env_value('DB_HOST', defined('DB_HOST') ? DB_HOST : '127.0.0.1'),
        'port' => (int) env_value('DB_PORT', defined('DB_PORT') ? (string) DB_PORT : '3306'),
        'name' => env_value('DB_NAME', defined('DB_NAME') ? DB_NAME : null),
        'username' => env_value('DB_USERNAME', defined('DB_USERNAME') ? DB_USERNAME : null),
        'password' => env_value('DB_PASSWORD', defined('DB_PASSWORD') ? DB_PASSWORD : ''),
        'charset' => env_value('DB_CHARSET', defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'),
    ];
}

function database(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!class_exists(PDO::class)) {
        throw new RuntimeException('PDO is not available in this PHP environment.');
    }

    $settings = database_settings();
    if (($settings['name'] ?? '') === '' || ($settings['username'] ?? '') === '') {
        throw new RuntimeException('Database settings are incomplete. Configure DB_NAME, DB_USERNAME, and related MySQL settings before using the bulletin board.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string) $settings['host'],
        (int) $settings['port'],
        (string) $settings['name'],
        (string) $settings['charset']
    );

    $pdo = new PDO(
        $dsn,
        (string) $settings['username'],
        (string) $settings['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function database_transaction(callable $callback): mixed
{
    $pdo = database();

    if ($pdo->inTransaction()) {
        return $callback($pdo);
    }

    $pdo->beginTransaction();

    try {
        $result = $callback($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function valid_board_id(string $boardId): bool
{
    return isset(board_catalog()[$boardId]);
}

function valid_feedback_board_id(string $boardId): bool
{
    return valid_board_id($boardId);
}

function feedback_type_options(): array
{
    return [
        'praise_appreciation' => 'Praise/Appreciation',
        'suggestion' => 'Suggestion',
        'constructive_feedback' => 'Constructive Feedback',
        'concern' => 'Concern',
        'complaint' => 'Complaint',
        'other' => 'Other',
    ];
}

function feedback_types(): array
{
    return array_keys(feedback_type_options());
}

function feedback_type_label(string $type): string
{
    return feedback_type_options()[$type] ?? $type;
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

function ensure_attachment_root(): void
{
    if (is_dir(ATTACHMENT_ROOT)) {
        return;
    }

    if (!mkdir(ATTACHMENT_ROOT, 0775, true) && !is_dir(ATTACHMENT_ROOT)) {
        throw new RuntimeException('Unable to create the attachment storage directory.');
    }
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

function iso8601_to_mysql_datetime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return gmdate('Y-m-d H:i:s', $timestamp);
}

function mysql_datetime_to_iso8601(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value . ' UTC');
    if ($timestamp === false) {
        $timestamp = strtotime($value);
    }

    return $timestamp === false ? $value : gmdate('c', $timestamp);
}

function normalize_user_record(array $user): array
{
    $boardIds = $user['board_ids'] ?? [];
    if (!is_array($boardIds)) {
        $boardIds = [];
    }

    $boardIds = array_values(array_filter(array_map(
        static fn ($boardId): string => trim((string) $boardId),
        $boardIds
    ), 'valid_board_id'));

    return [
        'username' => trim((string) ($user['username'] ?? '')),
        'name' => trim((string) ($user['name'] ?? '')),
        'role' => trim((string) ($user['role'] ?? 'program_chair')),
        'default_username' => trim((string) ($user['default_username'] ?? '')),
        'default_password_hash' => (string) ($user['default_password_hash'] ?? ''),
        'password_hash' => (string) ($user['password_hash'] ?? ''),
        'is_locked' => (bool) ($user['is_locked'] ?? false),
        'totp_secret' => trim((string) ($user['totp_secret'] ?? '')),
        'totp_enabled' => (bool) ($user['totp_enabled'] ?? false),
        'totp_enabled_at' => mysql_datetime_to_iso8601((string) ($user['totp_enabled_at'] ?? '')),
        'board_ids' => array_values(array_unique($boardIds)),
    ];
}

function hydrate_users(?PDO $pdo = null): array
{
    $pdo ??= database();
    $users = [];

    $statement = $pdo->query('SELECT username, name, role, default_username, default_password_hash, password_hash, is_locked, totp_secret, totp_enabled, totp_enabled_at FROM users ORDER BY id ASC');
    foreach ($statement->fetchAll() as $row) {
        $user = normalize_user_record($row);
        $user['board_ids'] = [];
        $users[$user['username']] = $user;
    }

    if ($users === []) {
        return [];
    }

    $boardStatement = $pdo->query('SELECT u.username, ub.board_id FROM user_boards ub INNER JOIN users u ON u.id = ub.user_id ORDER BY u.id ASC, ub.sort_order ASC, ub.id ASC');
    foreach ($boardStatement->fetchAll() as $row) {
        $username = (string) ($row['username'] ?? '');
        if (!isset($users[$username])) {
            continue;
        }

        $boardId = trim((string) ($row['board_id'] ?? ''));
        if (valid_board_id($boardId)) {
            $users[$username]['board_ids'][] = $boardId;
        }
    }

    return array_values($users);
}

function all_users(): array
{
    return hydrate_users();
}

function persist_users(array $users, ?PDO $pdo = null): void
{
    $pdo ??= database();
    $users = array_map('normalize_user_record', $users);

    $pdo->exec('DELETE FROM user_boards');
    $pdo->exec('DELETE FROM users');

    $userStatement = $pdo->prepare('
        INSERT INTO users (username, name, role, default_username, default_password_hash, password_hash, is_locked, totp_secret, totp_enabled, totp_enabled_at)
        VALUES (:username, :name, :role, :default_username, :default_password_hash, :password_hash, :is_locked, :totp_secret, :totp_enabled, :totp_enabled_at)
    ');
    $boardStatement = $pdo->prepare('
        INSERT INTO user_boards (user_id, board_id, sort_order)
        VALUES (:user_id, :board_id, :sort_order)
    ');

    foreach ($users as $user) {
        if ($user['username'] === '' || $user['name'] === '' || $user['password_hash'] === '') {
            throw new RuntimeException('User records must include username, name, and password hash.');
        }

        $userStatement->execute([
            ':username' => $user['username'],
            ':name' => $user['name'],
            ':role' => $user['role'],
            ':default_username' => $user['default_username'] !== '' ? $user['default_username'] : $user['username'],
            ':default_password_hash' => $user['default_password_hash'] !== '' ? $user['default_password_hash'] : $user['password_hash'],
            ':password_hash' => $user['password_hash'],
            ':is_locked' => !empty($user['is_locked']) ? 1 : 0,
            ':totp_secret' => $user['totp_secret'] !== '' ? $user['totp_secret'] : null,
            ':totp_enabled' => !empty($user['totp_enabled']) ? 1 : 0,
            ':totp_enabled_at' => iso8601_to_mysql_datetime((string) ($user['totp_enabled_at'] ?? '')),
        ]);

        $userId = (int) $pdo->lastInsertId();
        foreach ($user['board_ids'] as $sortOrder => $boardId) {
            $boardStatement->execute([
                ':user_id' => $userId,
                ':board_id' => $boardId,
                ':sort_order' => $sortOrder,
            ]);
        }
    }
}

function mutate_users(callable $mutator): array
{
    return database_transaction(static function (PDO $pdo) use ($mutator): array {
        $users = hydrate_users($pdo);
        $updatedUsers = $mutator($users);
        if (!is_array($updatedUsers)) {
            throw new RuntimeException('User mutation failed.');
        }

        persist_users($updatedUsers, $pdo);

        return hydrate_users($pdo);
    });
}

function normalize_feedback_record(array $feedback): array
{
    return [
        'id' => (string) ($feedback['id'] ?? ''),
        'board_id' => (string) ($feedback['board_id'] ?? ''),
        'type' => (string) ($feedback['type'] ?? 'other'),
        'message' => trim((string) ($feedback['message'] ?? '')),
        'is_anonymous' => (bool) ($feedback['is_anonymous'] ?? true),
        'email' => (string) ($feedback['email'] ?? ''),
        'created_at' => mysql_datetime_to_iso8601((string) ($feedback['created_at'] ?? '')),
    ];
}

function normalize_feedback_pending_record(array $pending): array
{
    return [
        'board_id' => (string) ($pending['board_id'] ?? ''),
        'email' => strtolower(trim((string) ($pending['email'] ?? ''))),
        'type' => (string) ($pending['type'] ?? 'other'),
        'message' => trim((string) ($pending['message'] ?? '')),
        'is_anonymous' => (bool) ($pending['is_anonymous'] ?? true),
        'otp_hash' => (string) ($pending['otp_hash'] ?? ''),
        'requested_at' => mysql_datetime_to_iso8601((string) ($pending['requested_at'] ?? '')),
        'expires_at' => mysql_datetime_to_iso8601((string) ($pending['expires_at'] ?? '')),
    ];
}

function normalize_feedback_email(string $email): string
{
    return strtolower(trim($email));
}

function valid_feedback_email(string $email): bool
{
    return filter_var(normalize_feedback_email($email), FILTER_VALIDATE_EMAIL) !== false;
}

function feedback_otp_expiry_seconds(): int
{
    return 120;
}

function feedback_for_board(string $boardId): array
{
    if (!valid_feedback_board_id($boardId)) {
        return [];
    }

    $statement = database()->prepare('
        SELECT id, board_id, type, message, is_anonymous, email, created_at
        FROM feedback
        WHERE board_id = :board_id
        ORDER BY created_at DESC, id DESC
    ');
    $statement->execute([':board_id' => $boardId]);

    return array_map('normalize_feedback_record', $statement->fetchAll());
}

function pending_feedback_for_board(string $boardId): array
{
    if (!valid_feedback_board_id($boardId)) {
        return [];
    }

    $statement = database()->prepare('
        SELECT board_id, email, type, message, is_anonymous, otp_hash, requested_at, expires_at
        FROM feedback_pending
        WHERE board_id = :board_id
        ORDER BY requested_at DESC, id DESC
    ');
    $statement->execute([':board_id' => $boardId]);

    return array_map('normalize_feedback_pending_record', $statement->fetchAll());
}

function sort_feedback_latest_first(array &$feedback): void
{
    usort(
        $feedback,
        static fn (array $left, array $right): int => strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''))
    );
}

function persist_feedback_for_board(string $boardId, array $feedback, ?PDO $pdo = null): void
{
    $pdo ??= database();
    $statementDelete = $pdo->prepare('DELETE FROM feedback WHERE board_id = :board_id');
    $statementDelete->execute([':board_id' => $boardId]);

    $statementInsert = $pdo->prepare('
        INSERT INTO feedback (id, board_id, type, message, is_anonymous, email, created_at)
        VALUES (:id, :board_id, :type, :message, :is_anonymous, :email, :created_at)
    ');

    foreach ($feedback as $item) {
        $item = normalize_feedback_record($item);
        $statementInsert->execute([
            ':id' => $item['id'],
            ':board_id' => $boardId,
            ':type' => $item['type'],
            ':message' => $item['message'],
            ':is_anonymous' => $item['is_anonymous'] ? 1 : 0,
            ':email' => $item['email'] !== '' ? $item['email'] : null,
            ':created_at' => iso8601_to_mysql_datetime($item['created_at']) ?? gmdate('Y-m-d H:i:s'),
        ]);
    }
}

function persist_pending_feedback_for_board(string $boardId, array $pending, ?PDO $pdo = null): void
{
    $pdo ??= database();
    $statementDelete = $pdo->prepare('DELETE FROM feedback_pending WHERE board_id = :board_id');
    $statementDelete->execute([':board_id' => $boardId]);

    $statementInsert = $pdo->prepare('
        INSERT INTO feedback_pending (board_id, email, type, message, is_anonymous, otp_hash, requested_at, expires_at)
        VALUES (:board_id, :email, :type, :message, :is_anonymous, :otp_hash, :requested_at, :expires_at)
    ');

    foreach ($pending as $item) {
        $item = normalize_feedback_pending_record($item);
        $statementInsert->execute([
            ':board_id' => $boardId,
            ':email' => $item['email'],
            ':type' => $item['type'],
            ':message' => $item['message'],
            ':is_anonymous' => $item['is_anonymous'] ? 1 : 0,
            ':otp_hash' => $item['otp_hash'],
            ':requested_at' => iso8601_to_mysql_datetime($item['requested_at']) ?? gmdate('Y-m-d H:i:s'),
            ':expires_at' => iso8601_to_mysql_datetime($item['expires_at']) ?? gmdate('Y-m-d H:i:s'),
        ]);
    }
}

function mutate_feedback_for_board(string $boardId, callable $mutator): array
{
    if (!valid_feedback_board_id($boardId)) {
        throw new RuntimeException('Invalid board selected.');
    }

    return database_transaction(static function (PDO $pdo) use ($boardId, $mutator): array {
        $feedback = feedback_for_board($boardId);
        $updatedFeedback = $mutator($feedback);
        if (!is_array($updatedFeedback)) {
            throw new RuntimeException('Feedback mutation failed.');
        }

        persist_feedback_for_board($boardId, $updatedFeedback, $pdo);

        return feedback_for_board($boardId);
    });
}

function mutate_pending_feedback_for_board(string $boardId, callable $mutator): array
{
    if (!valid_feedback_board_id($boardId)) {
        throw new RuntimeException('Invalid board selected.');
    }

    return database_transaction(static function (PDO $pdo) use ($boardId, $mutator): array {
        $pending = pending_feedback_for_board($boardId);
        $updatedPending = $mutator($pending);
        if (!is_array($updatedPending)) {
            throw new RuntimeException('Pending feedback mutation failed.');
        }

        persist_pending_feedback_for_board($boardId, $updatedPending, $pdo);

        return pending_feedback_for_board($boardId);
    });
}

function prune_expired_pending_feedback(array $pending): array
{
    $now = gmdate('c');

    return array_values(array_filter(
        $pending,
        static fn (array $item): bool => (string) ($item['expires_at'] ?? '') > $now
    ));
}

function mailer_settings(): array
{
    return [
        'host' => env_value('SMTP_HOST', defined('SMTP_HOST') ? SMTP_HOST : null),
        'port' => (int) env_value('SMTP_PORT', defined('SMTP_PORT') ? (string) SMTP_PORT : '465'),
        'username' => env_value('SMTP_USERNAME', defined('SMTP_USERNAME') ? SMTP_USERNAME : null),
        'password' => env_value('SMTP_PASSWORD', defined('SMTP_PASSWORD') ? SMTP_PASSWORD : null),
        'encryption' => env_value('SMTP_ENCRYPTION', defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'ssl'),
        'from_email' => env_value('SMTP_FROM_EMAIL', defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : null),
        'from_name' => env_value('SMTP_FROM_NAME', defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'NU Lipa SACE Bulletin'),
    ];
}

function ensure_phpmailer_loaded(): void
{
    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return;
    }

    $vendorAutoload = APP_ROOT . '/vendor/autoload.php';
    if (is_file($vendorAutoload)) {
        require_once $vendorAutoload;
    }

    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return;
    }

    $roots = [
        APP_ROOT . '/includes/PHPMailer/src',
        APP_ROOT . '/includes/vendor/phpmailer/src',
    ];

    foreach ($roots as $phpmailerRoot) {
        $requiredFiles = [
            $phpmailerRoot . '/Exception.php',
            $phpmailerRoot . '/PHPMailer.php',
            $phpmailerRoot . '/SMTP.php',
        ];

        $allPresent = true;
        foreach ($requiredFiles as $file) {
            if (!is_file($file)) {
                $allPresent = false;
                break;
            }
        }

        if ($allPresent) {
            foreach ($requiredFiles as $file) {
                require_once $file;
            }
            break;
        }
    }

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        throw new RuntimeException('PHPMailer is not installed. Add it via Composer vendor/autoload.php or place PHPMailer under includes/PHPMailer/src or includes/vendor/phpmailer/src.');
    }
}

function send_feedback_otp_email(string $email, string $otp, string $boardName): void
{
    ensure_phpmailer_loaded();

    $settings = mailer_settings();
    foreach (['host', 'port', 'username', 'password', 'from_email'] as $requiredKey) {
        if (empty($settings[$requiredKey])) {
            throw new RuntimeException('SMTP mail settings are incomplete. Configure Hostinger SMTP before sending OTP emails.');
        }
    }

    $subject = 'NU Lipa SACE Feedback OTP';
    $body = "Your one-time password for submitting feedback to {$boardName} is {$otp}. This code expires in 2 minutes.";

    try {
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = (string) $settings['host'];
        $mailer->Port = (int) $settings['port'];
        $mailer->SMTPAuth = true;
        $mailer->Username = (string) $settings['username'];
        $mailer->Password = (string) $settings['password'];
        $mailer->SMTPSecure = (string) $settings['encryption'];
        $mailer->CharSet = 'UTF-8';

        $mailer->setFrom((string) $settings['from_email'], (string) $settings['from_name']);
        $mailer->addAddress($email);
        $mailer->Subject = $subject;
        $mailer->Body = $body;
        $mailer->isHTML(false);
        $mailer->send();
    } catch (\PHPMailer\PHPMailer\Exception $exception) {
        throw new RuntimeException('Unable to send the OTP email. Check your Hostinger SMTP settings. ' . $exception->getMessage());
    }
}

function request_feedback_otp(string $boardId, string $type, string $message, string $email, bool $isAnonymous): string
{
    if (!valid_feedback_board_id($boardId)) {
        throw new RuntimeException('Please select a valid department.');
    }

    if (!in_array($type, feedback_types(), true)) {
        throw new RuntimeException('Please select a valid feedback type.');
    }

    $message = trim($message);
    if ($message === '') {
        throw new RuntimeException('Feedback message is required.');
    }

    $messageLength = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
    if ($messageLength > 3000) {
        throw new RuntimeException('Feedback message must be 3000 characters or fewer.');
    }

    $email = normalize_feedback_email($email);
    if (!valid_feedback_email($email)) {
        throw new RuntimeException('A valid email address is required for OTP verification.');
    }

    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $requestedAt = gmdate('c');
    $expiresAt = gmdate('c', time() + feedback_otp_expiry_seconds());

    mutate_pending_feedback_for_board($boardId, static function (array $pending) use ($boardId, $type, $message, $email, $isAnonymous, $otp, $requestedAt, $expiresAt): array {
        $pending = prune_expired_pending_feedback($pending);
        $pending = array_values(array_filter(
            $pending,
            static fn (array $item): bool => normalize_feedback_email((string) ($item['email'] ?? '')) !== $email
        ));

        $pending[] = [
            'board_id' => $boardId,
            'email' => $email,
            'type' => $type,
            'message' => $message,
            'is_anonymous' => $isAnonymous,
            'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
            'requested_at' => $requestedAt,
            'expires_at' => $expiresAt,
        ];

        return $pending;
    });

    send_feedback_otp_email($email, $otp, board_catalog()[$boardId]['name'] ?? $boardId);

    return $expiresAt;
}

function verify_feedback_otp_and_save(string $boardId, string $email, string $otp): void
{
    if (!valid_feedback_board_id($boardId)) {
        throw new RuntimeException('Please select a valid department.');
    }

    $email = normalize_feedback_email($email);
    if (!valid_feedback_email($email)) {
        throw new RuntimeException('A valid email address is required.');
    }

    $otp = trim($otp);
    if ($otp === '') {
        throw new RuntimeException('OTP is required.');
    }

    $matched = null;

    mutate_pending_feedback_for_board($boardId, static function (array $pending) use ($email, $otp, &$matched): array {
        $pending = prune_expired_pending_feedback($pending);
        $remaining = [];

        foreach ($pending as $item) {
            $sameEmail = normalize_feedback_email((string) ($item['email'] ?? '')) === $email;
            if (!$sameEmail) {
                $remaining[] = $item;
                continue;
            }

            if (!password_verify($otp, (string) ($item['otp_hash'] ?? ''))) {
                $remaining[] = $item;
                continue;
            }

            $matched = $item;
        }

        return $remaining;
    });

    if ($matched === null) {
        throw new RuntimeException('Invalid or expired OTP.');
    }

    mutate_feedback_for_board($boardId, static function (array $feedback) use ($matched, $email): array {
        $feedback[] = [
            'id' => uniqid('feedback_', true),
            'board_id' => (string) ($matched['board_id'] ?? ''),
            'type' => (string) ($matched['type'] ?? 'other'),
            'message' => (string) ($matched['message'] ?? ''),
            'is_anonymous' => (bool) ($matched['is_anonymous'] ?? true),
            'email' => !empty($matched['is_anonymous']) ? '' : $email,
            'created_at' => gmdate('c'),
        ];

        return $feedback;
    });
}

function normalize_notice_record(array $notice): array
{
    $notice['created_by'] = (string) ($notice['created_by'] ?? 'system');
    $notice['created_by_name'] = (string) ($notice['created_by_name'] ?? 'System Seeded Notice');
    $notice['pinned'] = (bool) ($notice['pinned'] ?? false);
    $notice['created_at'] = mysql_datetime_to_iso8601((string) ($notice['created_at'] ?? ''));
    $notice['updated_at'] = mysql_datetime_to_iso8601((string) ($notice['updated_at'] ?? ''));
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

function fetch_notice_tags(array $noticeIds, ?PDO $pdo = null): array
{
    $pdo ??= database();
    if ($noticeIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($noticeIds), '?'));
    $statement = $pdo->prepare("
        SELECT notice_id, tag
        FROM notice_tags
        WHERE notice_id IN ({$placeholders})
        ORDER BY sort_order ASC, id ASC
    ");
    $statement->execute(array_values($noticeIds));

    $tagsByNotice = [];
    foreach ($statement->fetchAll() as $row) {
        $tagsByNotice[(string) $row['notice_id']][] = trim((string) $row['tag']);
    }

    return $tagsByNotice;
}

function fetch_notices(?string $boardId = null, ?PDO $pdo = null): array
{
    $pdo ??= database();

    $sql = '
        SELECT
            id,
            board_id,
            category,
            audience,
            title,
            notice_date,
            visible_from,
            visible_until,
            body_text,
            pinned,
            created_by,
            created_by_name,
            created_at,
            updated_at,
            attachment_path,
            attachment_name,
            attachment_mime,
            attachment_kind,
            attachment_size
        FROM notices
    ';
    $params = [];

    if ($boardId !== null) {
        $sql .= ' WHERE board_id = :board_id';
        $params[':board_id'] = $boardId;
    }

    $sql .= ' ORDER BY created_at DESC, id DESC';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
    $noticeIds = array_map(static fn (array $row): string => (string) $row['id'], $rows);
    $tagsByNotice = fetch_notice_tags($noticeIds, $pdo);

    $notices = [];
    foreach ($rows as $row) {
        $noticeId = (string) $row['id'];
        $attachment = null;
        if (!empty($row['attachment_path'])) {
            $attachment = [
                'path' => (string) $row['attachment_path'],
                'name' => (string) ($row['attachment_name'] ?? ''),
                'mime' => (string) ($row['attachment_mime'] ?? ''),
                'kind' => (string) ($row['attachment_kind'] ?? ''),
                'size' => (int) ($row['attachment_size'] ?? 0),
            ];
        }

        $notices[] = normalize_notice_record([
            'id' => $noticeId,
            'board_id' => (string) $row['board_id'],
            'category' => (string) $row['category'],
            'audience' => (string) $row['audience'],
            'title' => (string) $row['title'],
            'date' => (string) $row['notice_date'],
            'visible_from' => (string) $row['visible_from'],
            'visible_until' => (string) $row['visible_until'],
            'text' => (string) $row['body_text'],
            'tags' => $tagsByNotice[$noticeId] ?? [],
            'pinned' => (bool) $row['pinned'],
            'created_by' => (string) $row['created_by'],
            'created_by_name' => (string) $row['created_by_name'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'attachment' => $attachment,
        ]);
    }

    return $notices;
}

function notices_for_board(string $boardId): array
{
    if (!valid_board_id($boardId)) {
        return [];
    }

    return fetch_notices($boardId);
}

function all_notices(): array
{
    return fetch_notices();
}

function persist_notices(array $notices, ?PDO $pdo = null): void
{
    $pdo ??= database();
    $notices = array_map('normalize_notice_record', $notices);

    $pdo->exec('DELETE FROM notice_tags');
    $pdo->exec('DELETE FROM notices');

    $noticeStatement = $pdo->prepare('
        INSERT INTO notices (
            id, board_id, category, audience, title, notice_date, visible_from, visible_until,
            body_text, pinned, created_by, created_by_name, created_at, updated_at,
            attachment_path, attachment_name, attachment_mime, attachment_kind, attachment_size
        )
        VALUES (
            :id, :board_id, :category, :audience, :title, :notice_date, :visible_from, :visible_until,
            :body_text, :pinned, :created_by, :created_by_name, :created_at, :updated_at,
            :attachment_path, :attachment_name, :attachment_mime, :attachment_kind, :attachment_size
        )
    ');
    $tagStatement = $pdo->prepare('
        INSERT INTO notice_tags (notice_id, tag, sort_order)
        VALUES (:notice_id, :tag, :sort_order)
    ');

    foreach ($notices as $notice) {
        if (!valid_board_id((string) $notice['board_id'])) {
            continue;
        }

        $attachment = normalize_attachment_record($notice['attachment'] ?? null);
        $noticeStatement->execute([
            ':id' => (string) $notice['id'],
            ':board_id' => (string) $notice['board_id'],
            ':category' => (string) $notice['category'],
            ':audience' => (string) $notice['audience'],
            ':title' => (string) $notice['title'],
            ':notice_date' => (string) $notice['date'],
            ':visible_from' => (string) $notice['visible_from'],
            ':visible_until' => (string) $notice['visible_until'],
            ':body_text' => (string) $notice['text'],
            ':pinned' => !empty($notice['pinned']) ? 1 : 0,
            ':created_by' => (string) $notice['created_by'],
            ':created_by_name' => (string) $notice['created_by_name'],
            ':created_at' => iso8601_to_mysql_datetime((string) $notice['created_at']) ?? gmdate('Y-m-d H:i:s'),
            ':updated_at' => iso8601_to_mysql_datetime((string) $notice['updated_at']) ?? gmdate('Y-m-d H:i:s'),
            ':attachment_path' => $attachment['path'] ?? null,
            ':attachment_name' => $attachment['name'] ?? null,
            ':attachment_mime' => $attachment['mime'] ?? null,
            ':attachment_kind' => $attachment['kind'] ?? null,
            ':attachment_size' => $attachment['size'] ?? null,
        ]);

        foreach (($notice['tags'] ?? []) as $index => $tag) {
            $tagStatement->execute([
                ':notice_id' => (string) $notice['id'],
                ':tag' => trim((string) $tag),
                ':sort_order' => $index,
            ]);
        }
    }
}

function mutate_notices(callable $mutator): array
{
    return database_transaction(static function (PDO $pdo) use ($mutator): array {
        $notices = fetch_notices(null, $pdo);
        $updatedNotices = $mutator($notices);
        if (!is_array($updatedNotices)) {
            throw new RuntimeException('Notice mutation failed.');
        }

        persist_notices($updatedNotices, $pdo);

        return fetch_notices(null, $pdo);
    });
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

function all_reactions(): array
{
    $statement = database()->query('SELECT notice_id, reaction_type, client_id FROM notice_reactions ORDER BY id ASC');
    $reactions = [];

    foreach ($statement->fetchAll() as $row) {
        $noticeId = (string) $row['notice_id'];
        $type = (string) $row['reaction_type'];
        $clientId = normalize_client_id((string) $row['client_id']);
        if ($noticeId === '' || !in_array($type, reaction_types(), true) || $clientId === '') {
            continue;
        }
        $reactions[$noticeId][$type][] = $clientId;
    }

    return $reactions;
}

function reactions_for_board(string $boardId): array
{
    if (!valid_board_id($boardId)) {
        return [];
    }

    $statement = database()->prepare('
        SELECT nr.notice_id, nr.reaction_type, nr.client_id
        FROM notice_reactions nr
        INNER JOIN notices n ON n.id = nr.notice_id
        WHERE n.board_id = :board_id
        ORDER BY nr.id ASC
    ');
    $statement->execute([':board_id' => $boardId]);

    $reactions = [];
    foreach ($statement->fetchAll() as $row) {
        $noticeId = (string) $row['notice_id'];
        $type = (string) $row['reaction_type'];
        $clientId = normalize_client_id((string) $row['client_id']);
        if ($clientId === '' || !in_array($type, reaction_types(), true)) {
            continue;
        }
        $reactions[$noticeId][$type][] = $clientId;
    }

    return $reactions;
}

function persist_reactions_for_board(string $boardId, array $reactions, ?PDO $pdo = null): void
{
    $pdo ??= database();

    $delete = $pdo->prepare('
        DELETE nr FROM notice_reactions nr
        INNER JOIN notices n ON n.id = nr.notice_id
        WHERE n.board_id = :board_id
    ');
    $delete->execute([':board_id' => $boardId]);

    $insert = $pdo->prepare('
        INSERT INTO notice_reactions (notice_id, reaction_type, client_id)
        VALUES (:notice_id, :reaction_type, :client_id)
    ');

    foreach ($reactions as $noticeId => $noticeReactions) {
        if (!is_array($noticeReactions)) {
            continue;
        }

        foreach (reaction_types() as $reactionType) {
            $clients = $noticeReactions[$reactionType] ?? [];
            if (!is_array($clients)) {
                continue;
            }

            $clients = array_values(array_unique(array_filter(array_map(
                static fn ($value): string => normalize_client_id((string) $value),
                $clients
            ))));

            foreach ($clients as $clientId) {
                $insert->execute([
                    ':notice_id' => (string) $noticeId,
                    ':reaction_type' => $reactionType,
                    ':client_id' => $clientId,
                ]);
            }
        }
    }
}

function mutate_reactions(string $boardId, callable $mutator): array
{
    if (!valid_board_id($boardId)) {
        throw new RuntimeException('Invalid board selected for reactions.');
    }

    return database_transaction(static function (PDO $pdo) use ($boardId, $mutator): array {
        $reactions = reactions_for_board($boardId);
        $updatedReactions = $mutator($reactions);
        if (!is_array($updatedReactions)) {
            throw new RuntimeException('Reaction mutation failed.');
        }

        persist_reactions_for_board($boardId, $updatedReactions, $pdo);

        return reactions_for_board($boardId);
    });
}

function board_id_for_notice_id(string $noticeId): ?string
{
    $statement = database()->prepare('SELECT board_id FROM notices WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $noticeId]);
    $boardId = (string) ($statement->fetchColumn() ?: '');

    return valid_board_id($boardId) ? $boardId : null;
}

function reaction_summary_for_notice(string $noticeId, ?string $clientId = null, ?string $boardId = null): array
{
    $summary = [
        'like' => ['count' => 0, 'reacted' => false],
        'heart' => ['count' => 0, 'reacted' => false],
    ];

    $clientId = $clientId !== null ? normalize_client_id($clientId) : null;
    $resolvedBoardId = $boardId !== null ? $boardId : board_id_for_notice_id($noticeId);
    if ($resolvedBoardId === null) {
        return $summary;
    }

    $all = reactions_for_board($resolvedBoardId);
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

    $boardId = board_id_for_notice_id($noticeId);
    if ($boardId === null) {
        throw new RuntimeException('Notice not found.');
    }

    mutate_reactions($boardId, static function (array $reactions) use ($noticeId, $reactionType, $clientId): array {
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

    return reaction_summary_for_notice($noticeId, $clientId, $boardId);
}

function today_ymd(): string
{
    return date('Y-m-d');
}

function format_datetime_label(string $value): string
{
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('F j, Y g:i A', $timestamp);
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
    $sessionUser = $_SESSION['user'] ?? null;
    if (!is_array($sessionUser) || empty($sessionUser['username'])) {
        return null;
    }

    $freshUser = find_user_by_username(all_users(), (string) $sessionUser['username']);
    if ($freshUser === null) {
        return $sessionUser;
    }

    $_SESSION['user'] = [
        'username' => $freshUser['username'],
        'name' => $freshUser['name'],
        'role' => $freshUser['role'],
        'is_locked' => !empty($freshUser['is_locked']),
        'board_ids' => $freshUser['board_ids'],
    ];

    return array_merge($sessionUser, $freshUser);
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
        'is_locked' => !empty($user['is_locked']),
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
    if (($user['role'] ?? '') === 'dean') {
        return true;
    }

    if (($user['role'] ?? '') === 'admin') {
        return false;
    }

    return in_array($boardId, $user['board_ids'] ?? [], true);
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

    if (($user['role'] ?? '') === 'dean') {
        return $catalog;
    }

    if (($user['role'] ?? '') === 'admin') {
        return [];
    }

    return array_filter(
        $catalog,
        static fn (array $board): bool => in_array($board['id'], $user['board_ids'] ?? [], true)
    );
}

function role_options(): array
{
    return [
        'dean' => 'Dean',
        'admin' => 'Admin',
        'program_chair' => 'Program Chair',
        'student_officer' => 'Student Officer',
    ];
}

function role_label(string $role): string
{
    return role_options()[$role] ?? $role;
}

function can_manage_users(array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), ['dean', 'admin'], true);
}

function can_manage_notice_module(array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), ['dean', 'program_chair', 'student_officer'], true);
}

function can_manage_calendar_module(array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), ['dean', 'program_chair', 'student_officer'], true);
}

function can_manage_calendar_board(array $user, string $boardId): bool
{
    return can_manage_board($user, $boardId);
}

function accessible_calendar_boards(array $user): array
{
    return accessible_boards($user);
}

function board_ids_for_role(string $role, array $submittedBoardIds = []): array
{
    if ($role === 'dean') {
        return array_keys(board_catalog());
    }

    if ($role === 'admin') {
        return [];
    }

    $validBoardIds = array_values(array_filter(array_map(
        static fn ($boardId): string => trim((string) $boardId),
        $submittedBoardIds
    ), 'valid_board_id'));

    return $validBoardIds === [] ? [] : [reset($validBoardIds)];
}

function primary_board_id_for_user(array $user): string
{
    return (string) (($user['board_ids'][0] ?? ''));
}

function authenticator_issuer(): string
{
    return 'NU Lipa SACE Bulletin';
}

function base32_alphabet(): string
{
    return 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
}

function base32_encode_string(string $input): string
{
    if ($input === '') {
        return '';
    }

    $alphabet = base32_alphabet();
    $binary = '';
    foreach (str_split($input) as $character) {
        $binary .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
    }

    $chunks = str_split($binary, 5);
    $encoded = '';
    foreach ($chunks as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $encoded .= $alphabet[bindec($chunk)];
    }

    return $encoded;
}

function base32_decode_string(string $input): string
{
    $input = strtoupper(preg_replace('/[^A-Z2-7]/', '', $input) ?? '');
    if ($input === '') {
        return '';
    }

    $alphabet = array_flip(str_split(base32_alphabet()));
    $binary = '';

    foreach (str_split($input) as $character) {
        if (!isset($alphabet[$character])) {
            throw new RuntimeException('Invalid authenticator secret.');
        }
        $binary .= str_pad(decbin($alphabet[$character]), 5, '0', STR_PAD_LEFT);
    }

    $bytes = str_split($binary, 8);
    $decoded = '';
    foreach ($bytes as $byte) {
        if (strlen($byte) < 8) {
            continue;
        }
        $decoded .= chr(bindec($byte));
    }

    return $decoded;
}

function generate_totp_secret(int $length = 20): string
{
    return base32_encode_string(random_bytes($length));
}

function user_has_totp_enabled(array $user): bool
{
    return !empty($user['totp_enabled']) && trim((string) ($user['totp_secret'] ?? '')) !== '';
}

function user_requires_totp(array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), ['dean', 'admin', 'program_chair', 'student_officer'], true);
}

function pending_totp_setup_key(string $username): string
{
    return 'pending_totp_setup_' . $username;
}

function issue_totp_setup_secret(array $user): string
{
    $secret = generate_totp_secret();
    $_SESSION[pending_totp_setup_key((string) $user['username'])] = $secret;
    return $secret;
}

function current_totp_setup_secret(array $user): string
{
    return (string) ($_SESSION[pending_totp_setup_key((string) $user['username'])] ?? '');
}

function clear_totp_setup_secret(array $user): void
{
    unset($_SESSION[pending_totp_setup_key((string) $user['username'])]);
}

function totp_provisioning_uri(array $user, string $secret): string
{
    $issuer = authenticator_issuer();
    $label = $issuer . ':' . (string) $user['username'];

    return 'otpauth://totp/' . rawurlencode($label)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

function totp_code_for_secret(string $secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string
{
    $timestamp ??= time();
    $counter = (int) floor($timestamp / $period);
    $secretBytes = base32_decode_string($secret);
    $counterBytes = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $counterBytes, $secretBytes, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $truncated = substr($hash, $offset, 4);
    $value = unpack('N', $truncated)[1] & 0x7FFFFFFF;
    $modulo = 10 ** $digits;

    return str_pad((string) ($value % $modulo), $digits, '0', STR_PAD_LEFT);
}

function verify_totp_code(string $secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if ($code === '') {
        return false;
    }

    $now = time();
    for ($offset = -$window; $offset <= $window; $offset++) {
        $candidate = totp_code_for_secret($secret, $now + ($offset * 30));
        if (hash_equals($candidate, $code)) {
            return true;
        }
    }

    return false;
}

function pending_login_user_key(): string
{
    return 'pending_login_username';
}

function begin_pending_totp_login(array $user): void
{
    $_SESSION[pending_login_user_key()] = (string) $user['username'];
}

function pending_totp_username(): string
{
    return (string) ($_SESSION[pending_login_user_key()] ?? '');
}

function clear_pending_totp_login(): void
{
    unset($_SESSION[pending_login_user_key()]);
}

function pending_totp_user(): ?array
{
    $username = pending_totp_username();
    if ($username === '') {
        return null;
    }

    return find_user_by_username(all_users(), $username);
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

function board_for_public(string $boardId, ?string $clientId = null): ?array
{
    $catalog = board_catalog();
    if (!isset($catalog[$boardId])) {
        return null;
    }

    $notices = notices_for_board($boardId);
    $today = today_ymd();

    sort_notices($notices);

    $board = $catalog[$boardId];
    $board['notices'] = [];

    foreach ($notices as $notice) {
        $isActive = is_notice_visible($notice, $today);
        $isPriorityWindow = is_priority_notice_visible($notice, $today);

        if (!$isActive && !$isPriorityWindow) {
            continue;
        }

        $board['notices'][] = [
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
            'reactions' => reaction_summary_for_notice((string) $notice['id'], $clientId, $boardId),
            'scope_status' => notice_scope_status($notice, $today),
        ];
    }

    return $board;
}

function group_boards_for_public(?string $clientId = null): array
{
    return array_values(array_map(
        static function (array $board): array {
            $board['notices'] = [];
            return $board;
        },
        board_catalog()
    ));
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
        static function (array $notice) use ($catalog, $clientId): array {
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
                'reactions' => reaction_summary_for_notice((string) $notice['id'], $clientId, $boardId),
            ];
        },
        $priority
    );
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
