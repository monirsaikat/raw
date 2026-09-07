<?php

// Authorization helpers around the Gate (see core/Gate.php). Abilities are
// declared in policies/*Policy.php (per model) and policies/gates.php.

function gate(): Gate
{
    return app(Gate::class);
}

function gate_define(string $ability, callable|string $callback): void
{
    gate()->define($ability, $callback);
}

function gate_policy(string $model, string $policy): void
{
    gate()->policy($model, $policy);
}

function gate_before(callable $callback): void
{
    gate()->before($callback);
}

function gate_after(callable $callback): void
{
    gate()->after($callback);
}

// can('update', $post) — for the logged-in user (or guest).
function can(string $ability, mixed ...$arguments): bool
{
    return gate()->allows($ability, ...$arguments);
}

function cannot(string $ability, mixed ...$arguments): bool
{
    return gate()->denies($ability, ...$arguments);
}

function can_any(array $abilities, mixed ...$arguments): bool
{
    return gate()->any($abilities, ...$arguments);
}

// Throws an AuthorizationException (403) when the ability is denied.
function authorize(string $ability, mixed ...$arguments): void
{
    gate()->authorize($ability, ...$arguments);
}

// Registers the Gate as a lazily-built singleton that loads the policies
// from config/auth.php and the definitions in policies/gates.php.
function gate_boot(): void
{
    app()->singleton(Gate::class, function (Container $app): Gate {
        $gate = new Gate(fn () => auth_user());

        // Available to gate_define() while the definitions file loads.
        $app->instance(Gate::class, $gate);

        foreach ((array) config('auth.policies', []) as $model => $policy) {
            $gate->policy($model, $policy);
        }

        $file = BASE_PATH . '/policies/gates.php';

        if (is_file($file)) {
            require $file;
        }

        return $gate;
    });
}

// Route middleware: 'can:ability' · 'can:update,Post@id' (model from a route
// parameter) · 'can:create,Post' (class name) · 'can:view,id' (raw value).
middleware('can', function (callable $next, string $ability, string ...$arguments) {
    $resolved = [];

    foreach ($arguments as $argument) {
        if (str_contains($argument, '@')) {
            [$model, $parameter] = explode('@', $argument, 2);

            if (!class_exists($model)) {
                throw new InvalidArgumentException("Unknown model [$model] in can:$ability middleware.");
            }

            $resolved[] = $model::findOrFail(route_parameter($parameter));
        } elseif (class_exists($argument)) {
            $resolved[] = $argument;
        } else {
            $resolved[] = route_parameter($argument);
        }
    }

    authorize($ability, ...$resolved);

    return $next();
});
