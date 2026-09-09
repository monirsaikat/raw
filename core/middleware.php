<?php

// Middleware registry and pipeline. A handler receives $next plus any
// parameters given after a colon in the route definition, e.g. the spec
// 'throttle:5,1' calls the 'throttle' handler as fn($next, '5', '1').

// $GLOBALS so the values are shared even when bootstrap.php is included from
// inside a function (test runners, static analysers).
$GLOBALS['middlewareRegistry'] = [];
$GLOBALS['globalMiddleware'] = ['csrf'];

// middleware('admin', fn (callable $next) => ...) or middleware('admin',
// AdminMiddleware::class) — a class built by the container with a
// handle(callable $next, ...$params) method.
function middleware(string $name, callable|string $handler): void
{
    global $middlewareRegistry;

    if (is_string($handler) && !is_callable($handler) && class_exists($handler)) {
        $class = $handler;
        $handler = fn (callable $next, ...$params) => app($class)->handle($next, ...$params);
    }

    $middlewareRegistry[$name] = $handler;
}

function middleware_exists(string $name): bool
{
    global $middlewareRegistry;

    return isset($middlewareRegistry[$name]);
}

// Middleware that wraps every route. Pass an array to replace the list.
function global_middleware(?array $set = null): array
{
    global $globalMiddleware;

    if ($set !== null) {
        $globalMiddleware = array_values($set);
    }

    return $globalMiddleware;
}

function add_global_middleware(string ...$names): void
{
    global $globalMiddleware;

    $globalMiddleware = array_values(array_unique(array_merge($globalMiddleware, $names)));
}

// 'name:a,b' → [handler, ['a', 'b']]
function resolve_middleware(string $spec): array
{
    global $middlewareRegistry;

    [$name, $params] = array_pad(explode(':', $spec, 2), 2, null);

    if (!isset($middlewareRegistry[$name])) {
        throw new RuntimeException("Unknown middleware [$name].");
    }

    return [
        $middlewareRegistry[$name],
        $params === null || $params === '' ? [] : array_map('trim', explode(',', $params)),
    ];
}

// Wraps $destination in each middleware, outermost first, onion-style:
// run_middleware(['a', 'b'], $dest) calls a(fn () => b(fn () => $dest())).
function run_middleware(array $specs, callable $destination)
{
    $pipeline = array_reduce(
        array_reverse($specs),
        function (callable $next, string $spec): callable {
            [$handler, $params] = resolve_middleware($spec);

            return fn () => $handler($next, ...$params);
        },
        $destination
    );

    return $pipeline();
}
