<?php

// Container helpers. app() is the container; app(Foo::class) resolves Foo.

function app(?string $abstract = null, array $parameters = [])
{
    $container = Container::getInstance();

    return $abstract === null ? $container : $container->make($abstract, $parameters);
}

// Registers the bindings declared in config/container.php.
function container_boot(): void
{
    $container = app();

    foreach ((array) config('container.bindings', []) as $abstract => $concrete) {
        $container->bind($abstract, $concrete);
    }

    foreach ((array) config('container.singletons', []) as $abstract => $concrete) {
        $container->singleton($abstract, $concrete);
    }

    foreach ((array) config('container.aliases', []) as $alias => $abstract) {
        $container->alias($alias, $abstract);
    }
}
