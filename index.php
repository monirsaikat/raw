<?php

define('APP_DEBUG', false);

require_once __DIR__ . '/core/autoload.php';
require_once __DIR__ . '/core/session/session.php';
require_once __DIR__ . '/core/view.php';
require_once __DIR__ . '/helpers/helpers.php';
require_once __DIR__ . '/core/route.php';

$routesCache = __DIR__ . '/bootstrap/cache/routes.php';

if (!APP_DEBUG && file_exists($routesCache)) {
    $routes = require $routesCache;
} else {
    require __DIR__ . '/routes/web.php';
}

route();
