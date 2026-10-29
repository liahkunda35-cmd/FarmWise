<?php

/**
 * Front controller for PHP's built-in server.
 * Returning false lets the server send a real public file (CSS, images).
 * Private paths are refused here so they are never downloaded.
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

if (preg_match('#^/assets/(.+)$#', $uri, $assetMatch)) {
    $relative = str_replace(['..', '\\'], '', $assetMatch[1]);
    $asset = __DIR__ . '/frontend/assets/' . $relative;
    if (is_file($asset)) {
        farmwise_send_file($asset);
        return true;
    }
}
if ($uri === '/frontend/config/api.js') {
    $apiClient = __DIR__ . '/frontend/config/api.js';
    if (is_file($apiClient)) {
        farmwise_send_file($apiClient);
        return true;
    }
}

if (farmwise_uri_is_private($uri)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo "Forbidden\n";
    return true;
}

$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';

/**
 * Paths that must never be served as static files, even when the project
 * directory is the document root.
 */
function farmwise_uri_is_private(string $uri): bool
{
    $path = str_replace('\\', '/', $uri);
    if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
        return $path !== '' && $path !== '/';
    }
    if (preg_match('#(^|/)\.env(\.|$|/)#i', $path)) {
        return true;
    }
    if (preg_match('#(^|/)\.(git|svn|hg)(/|$)#i', $path)) {
        return true;
    }
    if (preg_match('#^/(storage|app|config|views|scripts|docs|node_modules|vendor|backend)(/|$)#i', $path)) {
        return true;
    }
    if (preg_match('#^/frontend/(pages|components)(/|$)#i', $path) || preg_match('#^/frontend/bridge\.php$#i', $path)) {
        return true;
    }
    if (preg_match('#/(jwt\.secret)$#i', $path)) {
        return true;
    }
    if (preg_match('#\.(sqlite|sqlite3|sqlite-journal|pem|sql|bak)$#i', $path)) {
        return true;
    }
    if (preg_match('#^/(composer\.(json|lock)|package(-lock)?\.json|\.gitignore|\.htaccess)$#i', $path)) {
        return true;
    }
    return false;
}

function farmwise_send_file(string $file): void
{
    $types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string) filesize($file));
    readfile($file);
}
