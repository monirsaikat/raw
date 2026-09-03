<?php

function session_start_if_needed()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => env('SESSION_SECURE_COOKIE', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');

        session_start();
    }
}

function session_set($key, $value)
{
    session_start_if_needed();

    $_SESSION[$key] = $value;
}

function session_get($key, $default = null)
{
    session_start_if_needed();

    return $_SESSION[$key] ?? $default;
}

function session_has($key)
{
    session_start_if_needed();

    return isset($_SESSION[$key]);
}

function session_forget($key)
{
    session_start_if_needed();

    unset($_SESSION[$key]);
}

function session_flush()
{
    session_start_if_needed();

    $_SESSION = [];
}

function session_end()
{
    if (session_status() !== PHP_SESSION_NONE) {
        session_destroy();
    }
}