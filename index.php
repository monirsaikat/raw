<?php

// Web entry point. Apache rewrites every non-file request here (.htaccess);
// `php console.php serve` does the same through the built-in server.

// Mirror the .htaccess "!-f" rule for the CLI server: serve real files
// (assets/) directly instead of routing them.
if (PHP_SAPI === 'cli-server') {
    $requestedFile = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if ($requestedFile !== __DIR__ . '/' && is_file($requestedFile)) {
        return false;
    }
}

require_once __DIR__ . '/core/bootstrap.php';

// Production uses the compiled route table (`php console.php route:cache`);
// debug mode always reads routes/web.php so edits show up immediately.
$routesCache = BASE_PATH . '/bootstrap/cache/routes.php';
$cached = !APP_DEBUG && is_file($routesCache) ? require $routesCache : null;

if (is_array($cached) && isset($cached['routes'], $cached['names'])) {
    routes_load($cached);
} else {
    require BASE_PATH . '/routes/web.php';
}

route();
