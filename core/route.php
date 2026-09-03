<?php

$routes = [];

function get($path, $action, $name = null)
{
    global $routes;

    $routes['GET'][$path] = [
        'action' => $action,
        'name' => $name,
    ];
}

function redirect($url)
{
    header("Location: $url");
    exit;
}

function request($key = null)
{
    if ($key === null) {
        return $_REQUEST;
    }

    return $_REQUEST[$key] ?? null;
}

function post($path, $action, $name = null)
{
    global $routes;

    $routes['POST'][$path] = [
        'action' => $action,
        'name' => $name
    ];
}

function base_path()
{
    // dirname() returns a bare "\" (not "/") for a root script on Windows,
    // which rtrim(..., '/') doesn't strip — normalize before trimming.
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
}

function navigate(array $params)
{
    global $routes;

    $name = $params['name'] ?? null;

    foreach ($routes as $items) {
        foreach ($items as $path => $route) {
            if ($route['name'] === $name) {
                return base_path() . $path;
            }
        }
    }

    return '#';
}

function action($action, $params = [])
{
    if (is_callable($action)) {
        return $action(...$params);
    }

    [$controller, $method] = explode('@', $action);

    // Controller classes are resolved by core/autoload.php.
    return (new $controller)->$method(...$params);
}

function route()
{
    global $routes;

    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'];

    $base = base_path();

    $path = substr($uri, strlen($base));
    $path = '/' . trim($path, '/');

    $methodRoutes = $routes[$method] ?? [];

    $matched = null;
    $matches = [];

    // Static routes hit an O(1) array lookup, skipping regex work entirely.
    if (isset($methodRoutes[$path])) {
        $matched = $methodRoutes[$path];
    } else {
        foreach ($methodRoutes as $route => $item) {

            if (!str_contains($route, '{')) {
                continue;
            }

            $pattern = preg_replace(
                '#\{([^}]+)\}#',
                '([^/]+)',
                $route
            );

            if (preg_match("#^$pattern$#", $path, $matches)) {
                array_shift($matches);
                $matched = $item;

                break;
            }
        }
    }

    if ($matched === null) {
        http_response_code(404);
        echo view('views/404');

        return;
    }

    if ($method !== 'GET' && !csrf_verify()) {
        http_response_code(419);
        echo view('views/419');

        return;
    }

    echo action($matched['action'], $matches);
}