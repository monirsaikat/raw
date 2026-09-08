<?php

// Flash data lives for exactly one request after the one that set it, which
// is what the post-redirect-get pattern needs. flash('key', $v) stores for
// the NEXT request; flash('key') reads what the PREVIOUS request stored.
// Reads are repeatable within a request (nothing is destroyed on read), and
// a read never starts a session when the client has none (see session.php).

// Called once when the session starts: yesterday's "next" becomes today's
// "current" and the slate for the next request is cleared.
function flash_age(): void
{
    if (!isset($_SESSION['_flash'])) {
        return;
    }

    $next = $_SESSION['_flash']['next'] ?? [];

    if ($next === []) {
        unset($_SESSION['_flash']);

        return;
    }

    $_SESSION['_flash'] = ['current' => $next, 'next' => []];
}

function flash(string $key, $value = null)
{
    if ($value !== null) {
        session_start_if_needed();

        $_SESSION['_flash']['next'][$key] = $value;

        return null;
    }

    if (!session_readable()) {
        return null;
    }

    return $_SESSION['_flash']['current'][$key] ?? null;
}

// Makes a value visible in the current request (e.g. rendering a form
// straight after a failed validation without redirecting).
function flash_now(string $key, $value): void
{
    session_start_if_needed();

    $_SESSION['_flash']['current'][$key] = $value;
}

function flash_has(string $key): bool
{
    return flash($key) !== null;
}

function flash_all(): array
{
    if (!session_readable()) {
        return [];
    }

    return $_SESSION['_flash']['current'] ?? [];
}

// Keeps the current flash data alive for one more request.
function flash_keep(): void
{
    if (!session_readable()) {
        return;
    }

    $_SESSION['_flash']['next'] = array_merge(
        $_SESSION['_flash']['current'] ?? [],
        $_SESSION['_flash']['next'] ?? []
    );
}
