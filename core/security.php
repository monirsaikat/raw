<?php

function send_security_headers()
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header(
        "Content-Security-Policy: default-src 'self'; " .
        "script-src 'self' https://cdn.jsdelivr.net; " .
        "style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; " .
        "img-src 'self' data:; " .
        "base-uri 'self'; " .
        "form-action 'self'"
    );
}
