<?php

// Dot-notation access to config/*.php files: config('app.name') loads
// config/app.php once and returns $items['name']. Files are plain PHP arrays
// so they can read env() and compute values.

final class Config
{
    private static array $items = [];

    public static function get(string $key, $default = null)
    {
        $segments = explode('.', $key);
        $file = array_shift($segments);

        self::load($file);

        $value = self::$items[$file];

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public static function set(string $key, $value): void
    {
        $segments = explode('.', $key);
        $file = array_shift($segments);

        self::load($file);

        $target = &self::$items[$file];

        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
    }

    public static function has(string $key): bool
    {
        $missing = new stdClass();

        return self::get($key, $missing) !== $missing;
    }

    public static function reset(): void
    {
        self::$items = [];
    }

    private static function load(string $file): void
    {
        if (array_key_exists($file, self::$items)) {
            return;
        }

        $path = BASE_PATH . '/config/' . $file . '.php';

        self::$items[$file] = is_file($path) ? (array) require $path : [];
    }
}

function config(string $key, $default = null)
{
    return Config::get($key, $default);
}

function config_set(string $key, $value): void
{
    Config::set($key, $value);
}
