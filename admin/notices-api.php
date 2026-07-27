<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$user = current_user();
if ($user === null) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized.'], JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!can_manage_notice_module($user)) {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have access to notice publishing.'], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action !== 'delete_notice') {
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported action.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $noticeId = trim((string) ($_POST['notice_id'] ?? ''));
    $allNotices = all_notices();
    $target = find_notice_by_id($allNotices, $noticeId);
    if ($target === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Notice not found.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!can_edit_notice($user, $target)) {
        http_response_code(403);
        echo json_encode(['error' => 'You can only delete notices that you created.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $attachmentToDelete = normalize_attachment_record($target['attachment'] ?? null);

    mutate_notices(static function (array $notices) use ($noticeId, $user): array {
        foreach ($notices as $notice) {
            if (($notice['id'] ?? '') === $noticeId && !can_edit_notice($user, $notice)) {
                throw new RuntimeException('You can only delete notices that you created.');
            }
        }

        return array_values(array_filter(
            $notices,
            static fn (array $notice): bool => ($notice['id'] ?? '') !== $noticeId
        ));
    });

    delete_attachment_file($attachmentToDelete);

    echo json_encode([
        'ok' => true,
        'message' => 'Notice deleted.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$boardCatalog = board_catalog();
$today = today_ymd();
$notices = array_values(array_filter(
    all_notices(),
    static fn (array $notice): bool => can_edit_notice($user, $notice)
));
sort_notices($notices);

$payload = array_map(static function (array $notice) use ($boardCatalog, $today): array {
    return [
        'id' => (string) $notice['id'],
        'title' => (string) $notice['title'],
        'board_id' => (string) $notice['board_id'],
        'board_name' => (string) ($boardCatalog[$notice['board_id']]['name'] ?? $notice['board_id']),
        'category' => (string) $notice['category'],
        'audience' => (string) $notice['audience'],
        'date' => (string) $notice['date'],
        'visible_from' => (string) $notice['visible_from'],
        'visible_until' => (string) $notice['visible_until'],
        'status' => ucfirst(notice_scope_status($notice, $today)),
        'pinned' => !empty($notice['pinned']),
        'tags' => array_values($notice['tags'] ?? []),
        'text' => (string) $notice['text'],
        'created_by_name' => (string) ($notice['created_by_name'] ?? ''),
        'attachment' => $notice['attachment'],
        'edit_url' => 'index.php?edit=' . urlencode((string) $notice['id']),
    ];
}, $notices);

echo json_encode([
    'ok' => true,
    'notices' => $payload,
], JSON_UNESCAPED_SLASHES);
