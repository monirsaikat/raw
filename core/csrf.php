<?php

function csrf_token()
{
    if (!session_has('_csrf_token')) {
        session_set('_csrf_token', bin2hex(random_bytes(32)));
    }

    return session_get('_csrf_token');
}

function csrf_field($params = [])
{
    return '<input type="hidden" name="_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_verify()
{
    $token = request('_token') ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

    return is_string($token) && session_has('_csrf_token') && hash_equals(csrf_token(), $token);
}
