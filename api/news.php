<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$newsLinks = array_map(static function (array $newsLink): array {
    return [
        'id' => (int) ($newsLink['id'] ?? 0),
        'title' => (string) ($newsLink['title'] ?? ''),
        'summary' => (string) ($newsLink['summary'] ?? ''),
        'facebook_url' => (string) ($newsLink['facebook_url'] ?? ''),
        'image_url' => (string) ($newsLink['image_url'] ?? ''),
        'published_at' => (string) ($newsLink['created_at'] ?? ''),
    ];
}, all_news_links());

echo json_encode([
    'ok' => true,
    'news' => $newsLinks,
], JSON_UNESCAPED_SLASHES);
