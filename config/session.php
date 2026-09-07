<?php

return [
    // Cookie name. Use a distinct name per app on a shared host.
    'name' => env('SESSION_NAME', 'app_session'),

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
