<?php

return [
    // Shown in the layout, error pages and logs.
    'name' => env('APP_NAME', 'YourApp'),

    // 'local' | 'production' — only used for log lines and your own checks.
    'env' => env('APP_ENV', 'production'),

    // Debug mode renders exception pages with code and stack traces. Never
    // enable in production: it exposes file paths and internal messages.
    'debug' => (bool) env('APP_DEBUG', false),

    // Absolute URL of the app root. Leave empty to derive it per request.
    'url' => env('APP_URL', ''),

    // Path prefix when served from a sub-directory. Leave empty to derive
    // it from the request (works for XAMPP-style /project/ installs).
    'base_path' => env('APP_BASE_PATH'),

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    // Secret for signing (remember-me tokens). Generate with `php console.php key:generate`.
    'key' => env('APP_KEY', ''),

    // Minimum level written to storage/logs: debug, info, notice, warning, error, critical.
    'log_level' => env('LOG_LEVEL', 'debug'),

    // Appends ?v=<mtime> to asset() URLs so long-lived browser caching is safe.
    'asset_versioning' => true,
];
