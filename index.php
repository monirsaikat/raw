<?php

// Mirrors the .htaccess "!-f" rule for `php -S` local testing — Apache
// serves an existing file (assets/, etc.) directly and never reaches this
// script for it, but the CLI server always invokes the router unless told
// otherwise.
if (PHP_SAPI === 'cli-server') {
    $requestedFile = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if ($requestedFile !== __DIR__ . '/' && is_file($requestedFile)) {
        return false;
    }
}

require_once __DIR__ . '/core/env.php';

load_env(__DIR__ . '/.env');

define('APP_DEBUG', env('APP_DEBUG', false));

require_once __DIR__ . '/core/autoload.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/session/session.php';
require_once __DIR__ . '/core/view.php';
require_once __DIR__ . '/helpers/helpers.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/middleware.php';
require_once __DIR__ . '/core/route.php';
require_once __DIR__ . '/core/flash.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/core/validation.php';
require_once __DIR__ . '/core/auth.php';

send_security_headers();

set_exception_handler(function (Throwable $e) {
    http_response_code(500);

    if (APP_DEBUG) {
        echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES) . '</pre>';

        return;
    }

    try {
        echo view('views/500');
    } catch (Throwable) {
        echo '500 Internal Server Error';
    }
});

$routesCache = __DIR__ . '/bootstrap/cache/routes.php';

if (!APP_DEBUG && file_exists($routesCache)) {
    $routes = require $routesCache;
} else {
    require __DIR__ . '/routes/web.php';
}

route();
