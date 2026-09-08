<?php

return [
    // Connection used by dispatch()/Queue::push() unless one is named.
    // 'sync' runs jobs inline; 'database' needs `php console.php migrate`
    // and a running `php console.php queue:work`.
    'default' => env('QUEUE_CONNECTION', 'sync'),

    'connections' => [
        'sync' => [
            'driver' => 'sync',
            'queue' => 'default',
        ],

        'database' => [
            'driver' => 'database',
            // Database connection name from config/database.php; null = default.
            'connection' => env('QUEUE_DB_CONNECTION') ?: null,
            'table' => 'jobs',
            'queue' => env('QUEUE_NAME', 'default'),
            // Seconds after which a reserved-but-unfinished job (worker
            // crashed) is handed out again. Keep above your longest job.
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 90),
        ],
    ],

    'failed' => [
        'connection' => env('QUEUE_DB_CONNECTION') ?: null,
        'table' => 'failed_jobs',
    ],
];
