<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$boardCatalog = board_catalog();
$today = today_ymd();

$notices = array_values(array_filter(
    all_notices(),
    static fn (array $notice): bool => is_notice_visible($notice, $today)
));
sort_notices($notices);

$noticeUpdates = array_map(static function (array $notice) use ($boardCatalog): array {
    $boardId = (string) ($notice['board_id'] ?? '');

    return [
        'id' => (string) ($notice['id'] ?? ''),
        'title' => (string) ($notice['title'] ?? 'New notice'),
        'board_id' => $boardId,
        'board_name' => (string) ($boardCatalog[$boardId]['name'] ?? $boardId),
        'published_at' => (string) ($notice['created_at'] ?? $notice['date'] ?? ''),
    ];
}, $notices);

$calendarEvents = all_manual_calendar_events();
usort($calendarEvents, static function (array $left, array $right): int {
    return strcmp(
        (string) ($right['created_at'] ?? $right['event_date'] ?? ''),
        (string) ($left['created_at'] ?? $left['event_date'] ?? '')
    );
});

$calendarUpdates = array_map(static function (array $event) use ($boardCatalog): array {
    $boardId = (string) ($event['board_id'] ?? '');

    return [
        'id' => (string) ($event['id'] ?? ''),
        'title' => (string) ($event['title'] ?? 'New calendar entry'),
        'board_id' => $boardId,
        'board_name' => (string) ($boardCatalog[$boardId]['name'] ?? $boardId),
        'event_date' => (string) ($event['event_date'] ?? ''),
        'published_at' => (string) ($event['created_at'] ?? ''),
    ];
}, $calendarEvents);

echo json_encode([
    'ok' => true,
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DATE_ATOM),
    'notices' => $noticeUpdates,
    'calendar_events' => $calendarUpdates,
], JSON_UNESCAPED_SLASHES);
