<?php

declare(strict_types=1);

// The transport test's server, for PHP's built-in server.

$uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url(is_string($uri) ? $uri : '', PHP_URL_PATH);
if ($path === '/v1/moved') {
    header('Location: /v1/elsewhere', true, 302);
    return;
}
if ($path === '/v1/slow' || $path === '/v1/payment-links/slow') {
    usleep(1_000_000);
}
http_response_code($path === '/v1/missing' ? 404 : 200);
header('Content-Type: application/json');
header('X-Request-Id: req_local');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'body' => file_get_contents('php://input'),
    'agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
]);
