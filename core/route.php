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

function navigate(array $params)
{
    global $routes;

    $name = $params['name'] ?? null;

    foreach ($routes as $items) {
        foreach ($items as $path => $route) {
            if ($route['name'] === $name) {
                return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . $path;
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

    require_once __DIR__ . '/../controllers/' . $controller . '.php';

    return (new $controller)->$method(...$params);
}

function route()
{
    global $routes;

    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'];

    $base = dirname($_SERVER['SCRIPT_NAME']);

    $path = substr($uri, strlen($base));
    $path = '/' . trim($path, '/');

    foreach ($routes[$method] ?? [] as $route => $item) {

        $pattern = preg_replace(
            '#\{([^}]+)\}#',
            '([^/]+)',
            $route
        );

        if (preg_match("#^$pattern$#", $path, $matches)) {

            array_shift($matches);

            echo $item['action'](...$matches);

            return;
        }
    }

    http_response_code(404);
    exit('404 Not Found');
}