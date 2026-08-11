<?php

/**
 * Router script for PHP's built-in server (`php -S host:port -t public public/router.php`).
 *
 * Existing files under the document root are served directly by the server;
 * every other path is handed to the front controller.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Pressless\Http\DevServerRouter;

$requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
    ? $_SERVER['REQUEST_URI']
    : '/';

if (DevServerRouter::shouldServeStatic(__DIR__, $requestUri)) {
    return false;
}

require __DIR__ . '/index.php';
