<?php

// Loads KEY=value pairs from a .env file into $_ENV and $_SERVER (never
// putenv(): the process environment is shared between threads under a
// threaded web server, so values would leak between requests). Variables
// already present in the real environment win over the file. Supports `#`
// comments, `export KEY=value`, and single- or double-quoted values.

function load_env(string $path, bool $overwrite = false): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);

        $key = trim($key);
        $value = trim($value);

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }

        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        } else {
            // Strip a trailing inline comment: KEY=value # note
            $value = preg_replace('/\s+#.*$/', '', $value);
        }

        if (!$overwrite && env_from_environment($key) !== null) {
            continue;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

// A variable from the real environment, or null. Web servers hand
// variables over through $_SERVER/$_ENV (Apache SetEnv, FPM env[]);
// getenv() is consulted only under the CLI SAPIs, where the process
// environment belongs to this process alone. Under a threaded server it
// can carry values left behind by other requests.
function env_from_environment(string $key): ?string
{
    if (array_key_exists($key, $_ENV)) {
        return (string) $_ENV[$key];
    }

    if (array_key_exists($key, $_SERVER) && is_scalar($_SERVER[$key])) {
        return (string) $_SERVER[$key];
    }

    if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server' || PHP_SAPI === 'phpdbg') {
        $value = getenv($key);

        return $value === false ? null : $value;
    }

    return null;
}

// Reads a variable from .env or the real environment.
function env(string $key, $default = null)
{
    $value = env_from_environment($key);

    if ($value === null) {
        return $default;
    }

    return match (strtolower((string) $value)) {
        'true', '(true)' => true,
        'false', '(false)' => false,
        'null', '(null)' => null,
        'empty', '(empty)' => '',
        default => $value,
    };
}
