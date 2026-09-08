<?php

return [
    // Where sessions are stored: 'file' (PHP's native handler), 'database'
    // (the sessions table: php console.php session:table && migrate),
    // 'cookie' (encrypted, 4 KB limit) or 'array' (memory only, for tests).
    'driver' => env('SESSION_DRIVER', 'file'),

    // Table and connection for the database driver (null = default connection).
    'table' => env('SESSION_TABLE', 'sessions'),
    'connection' => env('SESSION_CONNECTION'),

    // Skip rewriting an unchanged session (saves a write per request).
    'lazy_write' => (bool) env('SESSION_LAZY_WRITE', true),

    // Cookie name. Use a distinct name per app on a shared host.
    'name' => env('SESSION_NAME', 'comfreephp_session'),

    // Idle timeout in minutes; 0 disables it. The cookie itself lasts until
    // the browser closes — persistent logins use "remember me" instead.
    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'path' => env('SESSION_PATH', '/'),
    'domain' => env('SESSION_DOMAIN', ''),

    // Force the Secure flag. It is also set automatically on HTTPS requests.
    'secure' => (bool) env('SESSION_SECURE_COOKIE', false),

    // 'Lax' blocks cross-site POSTs (CSRF defence in depth) but still sends
    // the cookie on normal link navigation; 'Strict' breaks external links.
    'same_site' => 'Lax',
];
