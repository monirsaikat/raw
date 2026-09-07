<?php

return [
    // Connection used by Database::table(), models and migrations unless
    // one is named explicitly (Database::connection('sqlite'), Model::$connection).
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', ''),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'unix_socket' => env('DB_SOCKET', ''),
            // Extra PDO options merged over the defaults (exceptions on,
            // assoc fetch, native prepares).
            'options' => [],
        ],

        // File-based SQLite; the path is relative to the project root.
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_SQLITE_DATABASE', 'storage/database.sqlite'),
        ],

        // In-memory SQLite, used by the test suite.
        'testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ],
    ],

    // Keep every query (with bindings and timing) for the debug page. On
    // automatically when APP_DEBUG is true.
    'log_queries' => (bool) env('DB_LOG_QUERIES', false),

    // Queries slower than this many milliseconds are logged as warnings. 0 disables.
    'slow_query_ms' => (float) env('DB_SLOW_QUERY_MS', 0),
];
