<?php

// General-purpose helpers for app code and templates. Request/response,
// routing, session, validation and auth helpers live in core/.

function view(string $template, array $data = []): string
{
    return View::instance()->render($template, $data);
}

function view_exists(string $template): bool
{
    return View::instance()->exists($template);
}

// HTML-escape for the rare places outside Smarty that echo user data.
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// asset('assets/css/app.css') → "/base/assets/css/app.css?v=1725700000".
// The version query string lets browsers cache assets for a year yet pick
// up changes immediately. Also a Smarty function: {asset path='...'}.
function asset($path): string
{
    if (is_array($path)) {
        return htmlspecialchars(asset((string) ($path['path'] ?? '')), ENT_QUOTES, 'UTF-8');
    }

    $path = ltrim($path, '/');
    $url = base_path() . '/' . $path;

    if (config('app.asset_versioning', true)) {
        $file = BASE_PATH . '/' . $path;

        if (is_file($file)) {
            $url .= '?v=' . filemtime($file);
        }
    }

    return $url;
}

function app_name(): string
{
    return (string) config('app.name', 'App');
}

function is_debug(): bool
{
    return APP_DEBUG;
}

// Prints as "Y-m-d H:i:s"; now()->addDays(3), now()->diffForHumans(), ...
function now(): DateTimeValue
{
    return DateTimeValue::now();
}

function today(): DateTimeValue
{
    return DateTimeValue::today();
}

function collect(iterable $items = []): Collection
{
    return new Collection($items);
}

function fake(): Fake
{
    static $fake = null;

    return $fake ??= new Fake();
}

function current_year($params = []): string
{
    return date('Y');
}

// {method_field method='DELETE'} inside a POST form reaches a DELETE route.
function method_field($params = []): string
{
    $method = strtoupper(is_array($params) ? (string) ($params['method'] ?? '') : (string) $params);

    return '<input type="hidden" name="_method" value="' . htmlspecialchars($method, ENT_QUOTES, 'UTF-8') . '">';
}

// "Rendered in 4.2 ms · 1.5 MB · 3 queries" — {perf_stats} in templates
// (the layout shows it in the footer when APP_DEBUG is on).
function perf_stats($params = []): string
{
    $parts = [
        round((microtime(true) - APP_START) * 1000, 1) . ' ms',
        round(memory_get_peak_usage(true) / 1048576, 1) . ' MB',
    ];

    $queries = count(Database::queryLog());

    if ($queries > 0 || APP_DEBUG) {
        $parts[] = $queries . ' ' . ($queries === 1 ? 'query' : 'queries');
    }

    return 'Rendered in ' . implode(' · ', $parts);
}

function dump(...$values): void
{
    foreach ($values as $value) {
        if (PHP_SAPI === 'cli') {
            var_dump($value);
        } else {
            echo '<pre style="background:#0f172a;color:#e2e8f0;padding:1rem;border-radius:8px;overflow:auto">'
                . e(print_r($value, true)) . '</pre>';
        }
    }
}

// Dump and die.
function dd(...$values): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    dump(...$values);

    exit(1);
}
