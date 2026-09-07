<?php

// Synchroniser-token CSRF protection. Every non-read request must carry the
// session token as a `_token` field ({csrf_field}) or an X-CSRF-TOKEN header
// (read it from {csrf_meta} in JavaScript).

function csrf_token(): string
{
    if (!session_has('_csrf_token')) {
        session_set('_csrf_token', bin2hex(random_bytes(32)));
    }

    return session_get('_csrf_token');
}

function csrf_field($params = []): string
{
    return '<input type="hidden" name="_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_meta($params = []): string
{
    return '<meta name="csrf-token" content="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify(?string $token = null): bool
{
    $token ??= request('_token') ?? request_header('X-CSRF-TOKEN');

    return is_string($token)
        && session_has('_csrf_token')
        && hash_equals(csrf_token(), $token);
}

middleware('csrf', function (callable $next) {
    if (!in_array(request_method(), ['GET', 'HEAD', 'OPTIONS'], true) && !csrf_verify()) {
        abort(419, 'Your session has expired. Please go back, refresh the page and try again.');
    }

    return $next();
});
