<?php

// Encryption helpers on top of Encrypter (AES-256-GCM keyed from APP_KEY)
// and encrypted cookies. cookie() encrypts on write, cookie_get() decrypts
// on read and answers null for a missing, tampered or foreign-key cookie,
// so app code never sees a value the client could have altered.

function encrypter(): Encrypter
{
    static $instances = [];

    $key = (string) config('app.key', '');

    // Keyed by APP_KEY so a config_set('app.key') in tests gets a new one.
    return $instances[$key] ??= new Encrypter($key);
}

function encrypt($value): string
{
    return encrypter()->encrypt($value);
}

function decrypt(string $payload)
{
    return encrypter()->decrypt($payload);
}

function encrypt_string(string $plain): string
{
    return encrypter()->encrypt_string($plain);
}

function decrypt_string(string $payload): string
{
    return encrypter()->decrypt_string($payload);
}

// ---------------------------------------------------------------- cookies --

// Cookie attributes shared with the session cookie: same path/domain, Secure
// on HTTPS, HttpOnly and SameSite=Lax unless overridden in $options.
function cookie_options(array $options = []): array
{
    return $options + [
        'path' => (string) config('session.path', '/'),
        'domain' => (string) config('session.domain', ''),
        'secure' => (bool) config('session.secure', false) || request_is_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

// cookie('theme', 'dark', 60 * 24 * 30) sets an encrypted cookie for 30 days
// (0 minutes = until the browser closes). cookie('theme') reads it back.
function cookie(string $name, ?string $value = null, int $minutes = 0, array $options = [])
{
    if ($value === null) {
        return cookie_get($name);
    }

    $payload = encrypt_string($value);

    // Visible to cookie_get() for the rest of this request, like a browser
    // that already holds the cookie. Also what the CLI test client reads.
    $_COOKIE[$name] = $payload;
    $GLOBALS['__queued_cookies'][$name] = ['value' => $value, 'minutes' => $minutes];

    if (PHP_SAPI === 'cli' || headers_sent()) {
        return null;
    }

    setcookie($name, $payload, cookie_options($options) + [
        'expires' => $minutes > 0 ? time() + $minutes * 60 : 0,
    ]);

    return null;
}

function cookie_get(string $name, ?string $default = null): ?string
{
    $raw = $_COOKIE[$name] ?? null;

    if (!is_string($raw) || $raw === '') {
        return $default;
    }

    try {
        return decrypt_string($raw);
    } catch (DecryptException) {
        return $default;
    }
}

function cookie_has(string $name): bool
{
    return cookie_get($name) !== null;
}

function cookie_forget(string $name, array $options = []): void
{
    unset($_COOKIE[$name], $GLOBALS['__queued_cookies'][$name]);

    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    setcookie($name, '', cookie_options($options) + ['expires' => time() - 3600]);
}

// Cookies written by cookie() during this request, as plain values — what a
// test asserts against, since the CLI has no Set-Cookie headers.
function cookies_queued(): array
{
    return $GLOBALS['__queued_cookies'] ?? [];
}
