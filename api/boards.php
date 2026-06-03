<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$clientId = normalize_client_id((string) ($_GET['client_id'] ?? ''));
$boardId = trim((string) ($_GET['board_id'] ?? ''));

if ($boardId !== '') {
    $board = board_for_public($boardId, $clientId !== '' ? $clientId : null);

    if ($board === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Board not found'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'today' => today_ymd(),
        'board' => $board,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'defaultBoardId' => 'sace',
    'today' => today_ymd(),
    'priorityNotices' => priority_notices_for_public($clientId !== '' ? $clientId : null),
    'boards' => group_boards_for_public($clientId !== '' ? $clientId : null),
], JSON_UNESCAPED_SLASHES);
