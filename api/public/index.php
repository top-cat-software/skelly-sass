<?php

declare(strict_types=1);

/**
 * Front controller router — dispatches to the correct Symfony kernel
 * based on the request URI prefix.
 *
 * /api/*  → ApiKernel  (api.php)
 * /auth/* → AuthKernel (auth.php)
 *
 * Used by PHP's built-in server and as the default entry point.
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?? '/';

if (str_starts_with($path, '/api')) {
    require __DIR__ . '/api.php';
    return;
}

if (str_starts_with($path, '/auth')) {
    require __DIR__ . '/auth.php';
    return;
}

// Default: return 404 for unrouted paths.
// In production, Traefik routes non-API/auth paths to the frontend.
http_response_code(404);
echo json_encode([
    'type' => 'https://docs.skelly-saas.dev/errors/not-found',
    'title' => 'Not Found',
    'status' => 404,
    'detail' => 'No application handles this path. API routes start with /api, auth routes start with /auth.',
]);
