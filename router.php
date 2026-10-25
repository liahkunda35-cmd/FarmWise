<?php

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$file = __DIR__ . $uri;
$blocked = (bool) preg_match('#^/(storage|app|config|views)(/|$)#', $uri);
if (!$blocked && $uri !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
