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

$action = trim((string) ($payload['action'] ?? ''));
$boardId = trim((string) ($payload['board_id'] ?? ''));

try {
    if ($action === 'request_otp') {
        $type = trim((string) ($payload['type'] ?? ''));
        $message = trim((string) ($payload['message'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $isAnonymous = !empty($payload['is_anonymous']);

        $expiresAt = request_feedback_otp($boardId, $type, $message, $email, $isAnonymous);

        echo json_encode([
            'ok' => true,
            'expires_at' => $expiresAt,
            'message' => 'OTP sent. Enter it within 2 minutes to save your feedback.',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'verify_otp') {
        $email = trim((string) ($payload['email'] ?? ''));
        $otp = trim((string) ($payload['otp'] ?? ''));

        verify_feedback_otp_and_save($boardId, $email, $otp);

        echo json_encode([
            'ok' => true,
            'message' => 'Feedback submitted successfully.',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    throw new RuntimeException('Invalid feedback action.');
} catch (RuntimeException $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES);
}
