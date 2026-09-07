<?php

// Shared bootstrap for every entry point: index.php (web), console.php (CLI)
// and the test runner. Loads the environment, config, error handling and the
// core modules in dependency order. Include this once; it is a no-op after.

if (defined('BASE_PATH')) {
    return;
}

define('BASE_PATH', str_replace('\\', '/', dirname(__DIR__)));
define('APP_START', microtime(true));

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/Config.php';

load_env(BASE_PATH . '/.env');

define('APP_DEBUG', (bool) config('app.debug', false));

date_default_timezone_set((string) config('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/strings.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/errors.php';

register_error_handlers();

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/session/session.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/route.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/throttle.php';
require_once BASE_PATH . '/helpers/helpers.php';

// App-level middleware lives in middleware/*.php. Each file registers one or
// more handlers with middleware('name', fn). Scaffold one with make:middleware.
foreach (glob(BASE_PATH . '/middleware/*.php') ?: [] as $file) {
    require_once $file;
}
