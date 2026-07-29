<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$user = current_user();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($user === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized.'], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!can_manage_calendar_module($user)) {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have access to calendar management.'], JSON_UNESCAPED_SLASHES);
    exit;
}

if (user_requires_totp($user) && !user_has_totp_enabled($user)) {
    http_response_code(403);
    echo json_encode(['error' => 'Complete authenticator setup before managing calendar entries.'], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();

        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action !== 'delete_calendar_event') {
            http_response_code(400);
            echo json_encode(['error' => 'Unsupported action.'], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $eventId = trim((string) ($_POST['calendar_event_id'] ?? ''));
        $target = find_manual_calendar_event_by_id(all_manual_calendar_events(), $eventId);
        if ($target === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Calendar entry not found.'], JSON_UNESCAPED_SLASHES);
            exit;
        }

        if (!can_edit_manual_calendar_event($user, $target)) {
            http_response_code(403);
            echo json_encode(['error' => 'You can only delete manual calendar entries that you created.'], JSON_UNESCAPED_SLASHES);
            exit;
        }

        mutate_manual_calendar_events(static function (array $events) use ($eventId, $user): array {
            foreach ($events as $event) {
                if (($event['id'] ?? '') === $eventId && !can_edit_manual_calendar_event($user, $event)) {
                    throw new RuntimeException('You can only delete manual calendar entries that you created.');
                }
            }

            return array_values(array_filter(
                $events,
                static fn (array $event): bool => ($event['id'] ?? '') !== $eventId
            ));
        });

        echo json_encode([
            'ok' => true,
            'message' => 'Calendar entry deleted.',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['error' => 'Method not allowed.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $boardCatalog = board_catalog();
    $events = array_values(array_filter(
        all_manual_calendar_events(),
        static fn (array $event): bool => can_edit_manual_calendar_event($user, $event)
    ));
    usort($events, static function (array $left, array $right): int {
        $leftKey = (string) (($left['event_date'] ?? '') . ' ' . ($left['start_time'] ?? ''));
        $rightKey = (string) (($right['event_date'] ?? '') . ' ' . ($right['start_time'] ?? ''));

        return strcmp($rightKey, $leftKey);
    });

    $payload = array_map(static function (array $event) use ($boardCatalog): array {
        $boardId = (string) ($event['board_id'] ?? '');

        return [
            'id' => (string) ($event['id'] ?? ''),
            'title' => (string) ($event['title'] ?? ''),
            'board_id' => $boardId,
            'board_name' => (string) ($boardCatalog[$boardId]['name'] ?? $boardId),
            'event_date' => (string) ($event['event_date'] ?? ''),
            'start_time' => substr((string) ($event['start_time'] ?? ''), 0, 5),
            'end_time' => substr((string) ($event['end_time'] ?? ''), 0, 5),
            'created_by_name' => (string) ($event['created_by_name'] ?? ''),
            'edit_url' => 'index.php?calendar_edit=' . urlencode((string) ($event['id'] ?? '')),
        ];
    }, $events);

    echo json_encode([
        'ok' => true,
        'events' => $payload,
    ], JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES);
}
