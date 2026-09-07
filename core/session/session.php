<?php

// Lazy, hardened PHP sessions. Nothing starts until the first session_*()
// call, so responses that never touch the session pay no cookie/lock cost.
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

function session_set(string $key, $value): void
{
    session_start_if_needed();

    $_SESSION[$key] = $value;
}

function session_get(string $key, $default = null)
{
    session_start_if_needed();

    return $_SESSION[$key] ?? $default;
}

function session_has(string $key): bool
{
    session_start_if_needed();

    return isset($_SESSION[$key]);
}

function session_forget(string $key): void
{
    session_start_if_needed();

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
    session_start_if_needed();

    return $_SESSION;
}

function session_flush(): void
{
    session_start_if_needed();

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
