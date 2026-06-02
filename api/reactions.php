<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request payload'], JSON_UNESCAPED_SLASHES);
    exit;
}

$noticeId = trim((string) ($payload['notice_id'] ?? ''));
$reactionType = trim((string) ($payload['reaction_type'] ?? ''));
$clientId = normalize_client_id((string) ($payload['client_id'] ?? ''));

if ($noticeId === '' || $clientId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Notice and client identifiers are required'], JSON_UNESCAPED_SLASHES);
    exit;
}

if (find_notice_by_id(all_notices(), $noticeId) === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Notice not found'], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $summary = toggle_notice_reaction($noticeId, $reactionType, $clientId);
    echo json_encode([
        'notice_id' => $noticeId,
        'reactions' => $summary,
    ], JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES);
}
