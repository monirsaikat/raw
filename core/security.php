<?php

// Security response headers, driven by config/security.php. A per-request
// CSP nonce lets templates run inline scripts safely:
//   <script nonce="{csp_nonce}">...</script>

function csp_nonce(): string
{
    static $nonce = null;

    return $nonce ??= base64_encode(random_bytes(16));
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');

    foreach ((array) config('security.headers', []) as $name => $value) {
        if ($value !== null && $value !== false && $value !== '') {
            header($name . ': ' . $value);
        }
    }

    $directives = [];

    foreach ((array) config('security.csp', []) as $directive => $sources) {
        $sources = implode(' ', (array) $sources);
        $sources = str_replace('{nonce}', csp_nonce(), $sources);
        $directives[] = trim($directive . ' ' . $sources);
    }

    if ($directives !== []) {
        header('Content-Security-Policy: ' . implode('; ', $directives));
    }

    // HSTS is only meaningful (and only honoured) over HTTPS.
    if (config('security.hsts', false) && request_is_secure()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
