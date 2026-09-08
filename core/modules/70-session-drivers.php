<?php

// Session storage drivers, chosen with SESSION_DRIVER (config/session.php):
//
//   file      PHP's native file handler (default, unchanged)
//   database  DatabaseSessionHandler on the `sessions` table
//   cookie    CookieSessionHandler: encrypted session in a cookie (4 KB max)
//   array     ArraySessionHandler: memory only, for tests and stateless runs
//
// session_start_if_needed() calls session_driver_install() right before
// session_start(), so the handler is in place for every web session.

function session_driver(): string
{
    return strtolower((string) config('session.driver', 'file'));
}

// The handler object for a driver, or null for the native file handler.
function session_driver_handler(?string $driver = null): ?SessionHandlerInterface
{
    $driver ??= session_driver();
    $lifetime = max(60, (int) config('session.lifetime', 120) * 60);

    return match ($driver) {
        'file', 'files', 'native', '' => null,
        'database', 'db' => new DatabaseSessionHandler(
            (string) config('session.table', 'sessions'),
            config('session.connection') ?: null,
            $lifetime
        ),
        'cookie' => new CookieSessionHandler(
            (string) config('session.name', 'app_session') . '_payload',
            $lifetime
        ),
        'array' => new ArraySessionHandler(),
        default => throw new RuntimeException("Unknown session driver [$driver]."),
    };
}

// Registers the configured handler with PHP. Returns the handler, or null
// when the native file driver stays in charge.
function session_driver_install(): ?SessionHandlerInterface
{
    $handler = session_driver_handler();

    // Under the CLI $_SESSION is a plain array (see session.php), so there
    // is no PHP session to bind the handler to.
    if ($handler === null || PHP_SAPI === 'cli') {
        return $handler;
    }

    session_set_save_handler($handler, true);

    // The cookie driver must write before any output is sent, so the
    // session is closed from an output-buffer callback (which runs before
    // headers go out) instead of at shutdown.
    if ($handler instanceof CookieSessionHandler && PHP_SAPI !== 'cli') {
        ob_start(function (string $buffer): string {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            return $buffer;
        });
    }

    return $handler;
}
