<?php

// Loads KEY=value pairs from a .env file into $_ENV / putenv(). Variables
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

        if (!$overwrite && (array_key_exists($key, $_ENV) || getenv($key) !== false)) {
            continue;
        }

        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

function env(string $key, $default = null)
{
    $value = $_ENV[$key] ?? getenv($key);

    if ($value === false || $value === null) {
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
