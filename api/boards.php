<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

echo json_encode([
    'defaultBoardId' => 'sace',
    'today' => today_ymd(),
    'priorityNotices' => priority_notices_for_public(),
    'boards' => group_boards_for_public(),
], JSON_UNESCAPED_SLASHES);
