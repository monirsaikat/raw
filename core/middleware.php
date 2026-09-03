<?php

$middlewareRegistry = [];
$globalMiddleware = ['csrf'];

function middleware(string $name, callable $handler)
{
    global $middlewareRegistry;

    $middlewareRegistry[$name] = $handler;
}

function global_middleware(): array
{
    global $globalMiddleware;

    return $globalMiddleware;
}

function resolve_middleware(string $name): callable
{
    global $middlewareRegistry;

    if (!isset($middlewareRegistry[$name])) {
        throw new RuntimeException("Unknown middleware: $name");
    }

    return $middlewareRegistry[$name];
}

// Wraps $destination in each named middleware, outermost first, onion-style:
// run_middleware(['a', 'b'], $dest) calls a(fn () => b(fn () => $dest())).
function run_middleware(array $names, callable $destination)
{
    $pipeline = array_reduce(
        array_reverse($names),
        fn ($next, $name) => fn () => resolve_middleware($name)($next),
        $destination
    );

    return $pipeline();
}
