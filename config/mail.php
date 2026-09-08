<?php

return [
    // Mailer used by Mail::send(). 'log' writes messages to storage/logs
    // (the development default); 'smtp' and 'sendmail' deliver for real;
    // 'array' keeps them in memory for tests.
    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => (int) env('MAIL_PORT', 587),
            // 'tls' (STARTTLS), 'ssl' (implicit TLS, usually port 465) or null.
            'encryption' => env('MAIL_ENCRYPTION', 'tls') ?: null,
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => (int) env('MAIL_TIMEOUT', 30),
            // EHLO name; defaults to the host name of APP_URL or localhost.
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        'sendmail' => [
            'transport' => 'sendmail',
        ],

        'log' => [
            'transport' => 'log',
        ],

        'array' => [
            'transport' => 'array',
        ],
    ],

    // Default sender for every message that does not set its own.
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'ComfreePHP')),
    ],
];
