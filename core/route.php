<?php

// Routing. Routes are plain arrays (so `route:cache` can var_export them),
// static paths are matched with an O(1) array lookup, and dynamic paths use
// regexes compiled once at registration time. Placeholders: {id}, {id:\d+},
// {slug?} (optional, must be the last segment).

$routes = [];       // [METHOD][path] => route definition
$routeNames = [];   // name => path template
$routeGroup = ['prefix' => '', 'middleware' => [], 'name' => ''];
$currentRoute = null;

function routes_reset(): void
{
    global $routes, $routeNames, $routeGroup, $currentRoute;

    $routes = [];
    $routeNames = [];
    $routeGroup = ['prefix' => '', 'middleware' => [], 'name' => ''];
    $currentRoute = null;
}

function routes_all(): array
{
    global $routes;

    return $routes;
}

function route_names(): array
{
    global $routeNames;

    return $routeNames;
}

// Restores the arrays produced by `route:cache`.
function routes_load(array $payload): void
{
    global $routes, $routeNames;

    $routes = $payload['routes'] ?? [];
    $routeNames = $payload['names'] ?? [];
}

function add_route(array $methods, string $path, $action, ?string $name = null, array $middleware = []): array
{
    global $routes, $routeNames, $routeGroup;

    $path = '/' . trim($routeGroup['prefix'] . '/' . trim($path, '/'), '/');

    $route = [
        'path' => $path,
        'methods' => array_map('strtoupper', $methods),
        'action' => $action,
        'name' => $name !== null ? $routeGroup['name'] . $name : null,
        'middleware' => array_values(array_unique(array_merge($routeGroup['middleware'], $middleware))),
    ];

    if (str_contains($path, '{')) {
        [$route['pattern'], $route['params']] = compile_route($path);
    }

    foreach ($route['methods'] as $method) {
        $routes[$method][$path] = $route;
    }

    if ($route['name'] !== null) {
        $routeNames[$route['name']] = $path;
    }

    return $route;
}

function get(string $path, $action, ?string $name = null, array $middleware = []): array
{
    return add_route(['GET'], $path, $action, $name, $middleware);
}

function post(string $path, $action, ?string $name = null, array $middleware = []): array
{
    return add_route(['POST'], $path, $action, $name, $middleware);
}

function put(string $path, $action, ?string $name = null, array $middleware = []): array
{
    return add_route(['PUT'], $path, $action, $name, $middleware);
}

function patch(string $path, $action, ?string $name = null, array $middleware = []): array
{
    return add_route(['PATCH'], $path, $action, $name, $middleware);
}

function delete(string $path, $action, ?string $name = null, array $middleware = []): array
{
    return add_route(['DELETE'], $path, $action, $name, $middleware);
}

function any(string $path, $action, ?string $name = null, array $middleware = []): array
{
    return add_route(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $path, $action, $name, $middleware);
}

function route_map(array $methods, string $path, $action, ?string $name = null, array $middleware = []): array
{
    return add_route($methods, $path, $action, $name, $middleware);
}

// group(['prefix' => '/admin', 'middleware' => ['auth'], 'name' => 'admin.'], fn () => ...)
function group(array $attributes, callable $callback): void
{
    global $routeGroup;

    $previous = $routeGroup;

    $routeGroup = [
        'prefix' => rtrim($previous['prefix'] . '/' . trim((string) ($attributes['prefix'] ?? ''), '/'), '/'),
        'middleware' => array_merge($previous['middleware'], (array) ($attributes['middleware'] ?? [])),
        'name' => $previous['name'] . (string) ($attributes['name'] ?? ''),
    ];

    try {
        $callback();
    } finally {
        $routeGroup = $previous;
    }
}

// "/user/{id:\d+}/{tab?}" → ['#^/user/(?P<id>\d+)(?:/(?P<tab>[^/]+))?$#D', ['id', 'tab']]
function compile_route(string $path): array
{
    $params = [];
    $pattern = '';
    $parts = preg_split('#(\{[^}]+\})#', $path, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    foreach ($parts as $part) {
        if ($part[0] === '{' && preg_match('#^\{([A-Za-z_][A-Za-z0-9_]*)(?::(.+?))?(\?)?\}$#', $part, $m)) {
            $name = $m[1];
            $regex = ($m[2] ?? '') !== '' ? $m[2] : '[^/]+';
            $optional = ($m[3] ?? '') === '?';
            $params[] = $name;
            $group = '(?P<' . $name . '>' . $regex . ')';

            if ($optional && str_ends_with($pattern, '/')) {
                $pattern = substr($pattern, 0, -1) . '(?:/' . $group . ')?';
            } elseif ($optional) {
                $pattern .= $group . '?';
            } else {
                $pattern .= $group;
            }

            continue;
        }

        $pattern .= preg_quote($part, '#');
    }

    return ['#^' . $pattern . '$#D', $params];
}

// Returns ['route' => ..., 'params' => [name => value]] or null. HEAD falls
// back to GET routes, as HTTP requires.
function match_route(string $method, string $path): ?array
{
    global $routes;

    $method = strtoupper($method);
    $candidates = $method === 'HEAD' ? ['HEAD', 'GET'] : [$method];

    foreach ($candidates as $candidate) {
        $table = $routes[$candidate] ?? [];

        if (isset($table[$path])) {
            return ['route' => $table[$path], 'params' => []];
        }

        foreach ($table as $route) {
            if (!isset($route['pattern'])) {
                continue;
            }

            if (preg_match($route['pattern'], $path, $matches, PREG_UNMATCHED_AS_NULL)) {
                $params = [];

                foreach ($route['params'] as $name) {
                    $params[$name] = isset($matches[$name]) ? rawurldecode($matches[$name]) : null;
                }

                return ['route' => $route, 'params' => $params];
            }
        }
    }

    return null;
}

// Methods that DO have a route for $path — used to answer 405 with Allow.
function route_allowed_methods(string $path): array
{
    global $routes;

    $allowed = [];

    foreach (array_keys($routes) as $method) {
        if (match_route($method, $path) !== null) {
            $allowed[] = $method;
        }
    }

    if (in_array('GET', $allowed, true) && !in_array('HEAD', $allowed, true)) {
        $allowed[] = 'HEAD';
    }

    return $allowed;
}

function dispatch(string $method, string $path)
{
    global $currentRoute;

    $match = match_route($method, $path);

    if ($match === null) {
        $allowed = route_allowed_methods($path);

        if ($allowed !== []) {
            abort(405, '', ['Allow' => implode(', ', $allowed)]);
        }

        abort(404);
    }

    $currentRoute = $match['route'];
    $currentRoute['parameters'] = $match['params'];
    $params = array_values($match['params']);
    $chain = array_merge(global_middleware(), $match['route']['middleware'] ?? []);

    return run_middleware($chain, fn () => action($match['route']['action'], $params));
}

// Named parameters of the matched route: route_parameter('id').
function route_parameters(): array
{
    return current_route()['parameters'] ?? [];
}

function route_parameter(string $name, $default = null)
{
    return route_parameters()[$name] ?? $default;
}

// Web entry point: security headers, dispatch, send. Output is buffered so
// an exception thrown mid-render can still replace the page cleanly.
function route(): void
{
    ob_start();

    send_security_headers();

    $method = request_method();
    $result = dispatch($method, request_path());

    // Remember the last page for back() when a Referer is missing.
    if ($method === 'GET' && session_status() === PHP_SESSION_ACTIVE && !wants_json() && !request_is_ajax()) {
        $_SESSION['_previous_url'] = request_url();
    }

    if (!headers_sent()) {
        header('X-Response-Time: ' . round((microtime(true) - APP_START) * 1000, 2) . 'ms');
    }

    send_response($result);

    ob_end_flush();
}

// Invokes 'Controller@method', an invokable controller class name, a
// [class, method] pair, or a closure. Controllers are built by the
// container, so constructor and method dependencies are injected; route
// parameters fill the remaining parameters in order, and a parameter typed
// with a Model class receives the model looked up by key (404 when missing).
function action($action, array $params = [])
{
    $container = app();

    if (is_string($action) && str_contains($action, '@')) {
        [$controller, $method] = explode('@', $action, 2);

        if (!class_exists($controller)) {
            throw new RuntimeException("Controller [$controller] not found.");
        }

        if (!method_exists($controller, $method)) {
            throw new RuntimeException("Method [$method] not found on [$controller].");
        }

        $callable = [$container->make($controller), $method];
    } elseif (is_string($action) && class_exists($action) && method_exists($action, '__invoke')) {
        $callable = [$container->make($action), '__invoke'];
    } elseif (is_array($action) && count($action) === 2 && is_string($action[0])) {
        $callable = [$container->make($action[0]), $action[1]];
    } elseif (is_callable($action)) {
        $callable = $action;
    } else {
        throw new RuntimeException('Invalid route action.');
    }

    [$callable, $reflection] = $container->reflect($callable);

    return $container->call($callable, route_bindings($reflection, $params));
}

// Maps positional route parameters onto the action's parameters by name,
// binding Model-typed parameters to records (route model binding).
function route_bindings(ReflectionFunctionAbstract $function, array $params): array
{
    $bound = [];
    $index = 0;

    foreach ($function->getParameters() as $parameter) {
        $type = $parameter->getType();
        $class = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;

        if ($class !== null && is_subclass_of($class, Model::class)) {
            if (!array_key_exists($index, $params) || $params[$index] === null) {
                $index++;

                continue;
            }

            $value = $params[$index++];
            $bound[$parameter->getName()] = $value instanceof Model ? $value : $class::findOrFail($value);
        } elseif ($class === null && array_key_exists($index, $params)) {
            $value = $params[$index++];

            // An absent optional segment leaves the parameter's own default in place.
            if ($value !== null || !$parameter->isDefaultValueAvailable()) {
                $bound[$parameter->getName()] = $value;
            }
        }
    }

    return $bound;
}

// Path prefix when the app lives in a sub-directory ("/saikat/test1").
// Derived from the request, or fixed with config('app.base_path').
function base_path(): string
{
    $configured = config('app.base_path');

    if (is_string($configured)) {
        return rtrim($configured, '/');
    }

    if (PHP_SAPI === 'cli') {
        return '';
    }

    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    return $base === '.' ? '' : $base;
}

// Absolute URL of the app root: config('app.url') or derived from the request.
function app_url(): string
{
    $configured = rtrim((string) config('app.url', ''), '/');

    if ($configured !== '') {
        return $configured;
    }

    if (PHP_SAPI === 'cli') {
        return 'http://localhost';
    }

    return (request_is_secure() ? 'https' : 'http') . '://' . request_host() . base_path();
}

// url('about') → "/saikat/test1/about". Also a Smarty function: {url path='about'}.
function url($path = ''): string
{
    if (is_array($path)) {
        return htmlspecialchars(url((string) ($path['path'] ?? '')), ENT_QUOTES, 'UTF-8');
    }

    return base_path() . '/' . ltrim($path, '/');
}

// route_url('user', ['id' => 5, 'tab' => 'posts']) → "/user/5?tab=posts".
// Placeholders are filled from $params; leftovers become the query string.
function route_url(string $name, array $params = []): string
{
    global $routeNames;

    if (!isset($routeNames[$name])) {
        throw new RuntimeException("Route [$name] is not defined.");
    }

    $path = preg_replace_callback(
        '#/?\{([A-Za-z_][A-Za-z0-9_]*)(?::[^}]*?)?(\?)?\}#',
        function (array $m) use (&$params, $name): string {
            $key = $m[1];
            $optional = ($m[2] ?? '') === '?';
            $slash = str_starts_with($m[0], '/') ? '/' : '';

            if (isset($params[$key]) && $params[$key] !== '') {
                $value = rawurlencode((string) $params[$key]);
                unset($params[$key]);

                return $slash . $value;
            }

            if ($optional) {
                unset($params[$key]);

                return '';
            }

            throw new RuntimeException("Missing parameter [$key] for route [$name].");
        },
        $routeNames[$name]
    );

    $url = base_path() . ($path === '' ? '/' : $path);

    if ($params !== []) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

// Smarty function: {navigate name='user' id=$user.id} — output is escaped
// for use inside HTML attributes.
function navigate(array $params): string
{
    $name = (string) ($params['name'] ?? '');
    unset($params['name']);

    return htmlspecialchars(route_url($name, $params), ENT_QUOTES, 'UTF-8');
}

function current_route(): ?array
{
    global $currentRoute;

    return $currentRoute;
}

function current_route_name(): ?string
{
    return current_route()['name'] ?? null;
}

// route_is('home'), route_is('admin.*'). Also a Smarty modifier:
// {if 'home'|route_is} ... {/if}
function route_is(string ...$patterns): bool
{
    $name = current_route_name();

    if ($name === null) {
        return false;
    }

    foreach ($patterns as $pattern) {
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';

        if (preg_match($regex, $name)) {
            return true;
        }
    }

    return false;
}
