<?php

// Lazy, hardened PHP sessions. Nothing starts until the first session_*()
// call that needs one, so responses that never touch the session pay no
// cookie, lock or file cost. Reads go a step further: when the client sent no
// session cookie there is nothing stored for it yet, so session_get() answers
// with the default without creating a session. A cookie-less API request that
// only checks auth_user() therefore never writes a session file. Writes always
// start a session.
// Under the CLI (console, tests) $_SESSION is a plain array so the same code
// runs without a web SAPI. Settings live in config/session.php.

function session_start_if_needed(): void
{
    if (PHP_SAPI === 'cli') {
        if (empty($GLOBALS['__session_cli_started'])) {
            $GLOBALS['__session_cli_started'] = true;
            $_SESSION = $_SESSION ?? [];
            flash_age();
        }

        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $config = (array) config('session', []);
    $lifetime = (int) ($config['lifetime'] ?? 120);

    session_name((string) ($config['name'] ?? 'app_session'));

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => (string) ($config['path'] ?? '/'),
        'domain' => (string) ($config['domain'] ?? ''),
        'secure' => (bool) ($config['secure'] ?? false) || request_is_secure(),
        'httponly' => true,
        'samesite' => (string) ($config['same_site'] ?? 'Lax'),
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    if ($lifetime > 0) {
        ini_set('session.gc_maxlifetime', (string) ($lifetime * 60));
    }

    session_start();

    // Idle timeout: a session untouched for `lifetime` minutes is discarded.
    if ($lifetime > 0) {
        $last = $_SESSION['_last_activity'] ?? null;

        if (is_int($last) && time() - $last > $lifetime * 60) {
            $_SESSION = [];
            session_regenerate_id(true);
        }

        $_SESSION['_last_activity'] = time();
    }

    flash_age();
}

// True once a session is active for this request.
function session_started(): bool
{
    if (PHP_SAPI === 'cli') {
        return !empty($GLOBALS['__session_cli_started']);
    }

    return session_status() === PHP_SESSION_ACTIVE;
}

// Prepares a read. Starts the session only when one may already exist (the
// client sent a session cookie); returns false when there is nothing to read.
function session_readable(): bool
{
    if (session_started()) {
        return true;
    }

    if (PHP_SAPI !== 'cli' && !isset($_COOKIE[(string) config('session.name', 'app_session')])) {
        return false;
    }

    session_start_if_needed();

    return true;
}

function session_set(string $key, $value): void
{
    session_start_if_needed();

    $_SESSION[$key] = $value;
}

function session_get(string $key, $default = null)
{
    if (!session_readable()) {
        return $default;
    }

    return $_SESSION[$key] ?? $default;
}

function session_has(string $key): bool
{
    return session_readable() && isset($_SESSION[$key]);
}

function session_forget(string $key): void
{
    if (!session_readable()) {
        return;
    }

    unset($_SESSION[$key]);
}

// Get-and-forget in one step.
function session_pull(string $key, $default = null)
{
    $value = session_get($key, $default);
    session_forget($key);

    return $value;
}

function session_all(): array
{
    return session_readable() ? $_SESSION : [];
}

function session_flush(): void
{
    if (!session_readable()) {
        return;
    }

    $_SESSION = [];
}

// Issues a fresh session ID, keeping the data. Call on login/register/logout
// to prevent session fixation across a privilege change.
function session_regenerate(): void
{
    session_start_if_needed();

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

// Wipes the data and rotates the ID — a full logout of the session itself.
function session_invalidate(): void
{
    session_flush();
    session_regenerate();
}
