<?php

return [
    // Sent on every response. Set a value to null to drop a header.
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
    ],

    // Content-Security-Policy. {nonce} is replaced per request; add
    // nonce="{csp_nonce}" to inline <script> tags to allow them. Everything
    // else is same-origin only, which is why assets are self-hosted.
    'csp' => [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'nonce-{nonce}'"],
        'style-src' => ["'self'", "'unsafe-inline'"],
        'img-src' => ["'self'", 'data:'],
        'font-src' => ["'self'", 'data:'],
        'connect-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'self'"],
    ],

    // Strict-Transport-Security. Only sent over HTTPS; enable once the site
    // is served exclusively over TLS (browsers remember it for a year).
    'hsts' => (bool) env('HSTS_ENABLED', false),
];
