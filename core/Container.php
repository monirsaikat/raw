<?php

// Dependency injection container with reflection-based auto-wiring.
//
//   app()->bind(PaymentGateway::class, StripeGateway::class);
//   app()->singleton(Mailer::class, fn (Container $app) => new Mailer(config('mail')));
//   app(ReportBuilder::class);                        // constructor dependencies resolved recursively
//   app()->call([$controller, 'show'], ['id' => 5]);  // method injection
//
// Controllers, class-based middleware and policies are built through the
// container, so whatever they type-hint in a constructor is injected.
// Bindings live in config/container.php.

final class Container
{
    private static ?Container $instance = null;

    private array $bindings = [];     // abstract => ['concrete' => Closure|string, 'shared' => bool]
    private array $instances = [];    // shared objects, by abstract
    private array $aliases = [];      // alias => abstract
    private array $contextual = [];   // concrete => [abstract => Closure|string]
    private array $buildStack = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->instance(self::class, self::$instance);
        }

        return self::$instance;
    }

    public static function setInstance(?Container $container): void
    {
        self::$instance = $container;
    }

    // ------------------------------------------------------------ binding --

    // A new object every time; $concrete may be a class name or a closure
    // receiving (Container $app, array $parameters).
    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $abstract = $this->normalize($abstract);

        unset($this->instances[$abstract]);

        $this->bindings[$abstract] = ['concrete' => $concrete ?? $abstract, 'shared' => $shared];
    }

    // Built once, then reused.
    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function instance(string $abstract, object $instance): object
    {
        return $this->instances[$this->normalize($abstract)] = $instance;
    }

    public function alias(string $alias, string $abstract): void
    {
        $this->aliases[ltrim($alias, '\\')] = ltrim($abstract, '\\');
    }

    // Different implementation for one consumer only.
    public function when(string $concrete): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, ltrim($concrete, '\\'));
    }

    public function addContextualBinding(string $concrete, string $abstract, Closure|string $implementation): void
    {
        $this->contextual[ltrim($concrete, '\\')][ltrim($abstract, '\\')] = $implementation;
    }

    public function bound(string $abstract): bool
    {
        $abstract = $this->normalize($abstract);

        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    // Bound, or a class the container could build.
    public function has(string $abstract): bool
    {
        return $this->bound($abstract) || class_exists($this->normalize($abstract));
    }

    public function resolved(string $abstract): bool
    {
        return isset($this->instances[$this->normalize($abstract)]);
    }

    public function forget(string $abstract): void
    {
        $abstract = $this->normalize($abstract);

        unset($this->bindings[$abstract], $this->instances[$abstract]);
    }

    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->aliases = [];
        $this->contextual = [];
        $this->buildStack = [];

        $this->instance(self::class, $this);
    }

    // ---------------------------------------------------------- resolving --

    public function make(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->normalize($abstract);

        if (isset($this->instances[$abstract]) && $parameters === []) {
            return $this->instances[$abstract];
        }

        $binding = $this->bindings[$abstract] ?? null;
        $concrete = $binding['concrete'] ?? $abstract;

        if ($concrete instanceof Closure) {
            $object = $concrete($this, $parameters);
        } elseif ($concrete === $abstract) {
            $object = $this->build($concrete, $parameters);
        } else {
            $object = $this->make($concrete, $parameters);
        }

        if (($binding['shared'] ?? false) && $parameters === []) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    // Instantiates a class, resolving constructor dependencies.
    public function build(string $concrete, array $parameters = []): object
    {
        if (!class_exists($concrete)) {
            if (interface_exists($concrete)) {
                throw new ContainerException("[$concrete] is an interface and is not instantiable; bind it to a concrete class in config/container.php.");
            }

            throw new ContainerException("Class [$concrete] does not exist and is not bound.");
        }

        $reflection = new ReflectionClass($concrete);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException("[$concrete] is not instantiable; bind it to a concrete class in config/container.php.");
        }

        if (in_array($concrete, $this->buildStack, true)) {
            throw new ContainerException('Circular dependency: ' . implode(' -> ', [...$this->buildStack, $concrete]));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $concrete();
        }

        $this->buildStack[] = $concrete;

        try {
            $arguments = $this->resolveParameters($constructor, $parameters, $concrete);
        } finally {
            array_pop($this->buildStack);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    // Calls a closure, [object, method], 'Class@method', 'Class::method' or
    // invokable class, injecting type-hinted dependencies. $parameters may
    // be keyed by parameter name or given positionally.
    public function call(callable|array|string $callable, array $parameters = []): mixed
    {
        [$callable, $reflection] = $this->reflect($callable);

        $context = is_array($callable)
            ? (is_object($callable[0]) ? get_class($callable[0]) : $callable[0])
            : null;

        return $callable(...$this->resolveParameters($reflection, $parameters, $context));
    }

    // Normalises a callable and returns it with its reflection.
    public function reflect(callable|array|string $callable): array
    {
        if (is_string($callable) && str_contains($callable, '@')) {
            [$class, $method] = explode('@', $callable, 2);
            $callable = [$this->make($class), $method];
        } elseif (is_string($callable) && str_contains($callable, '::')) {
            $callable = explode('::', $callable, 2);
        } elseif (is_string($callable) && !function_exists($callable) && class_exists($callable)) {
            $callable = [$this->make($callable), '__invoke'];
        }

        if (is_array($callable)) {
            return [$callable, new ReflectionMethod($callable[0], $callable[1])];
        }

        if ($callable instanceof Closure || is_string($callable)) {
            return [$callable, new ReflectionFunction($callable)];
        }

        return [$callable, new ReflectionMethod($callable, '__invoke')];
    }

    // For each parameter, in order: a value passed by name, a positional
    // value that fits, a dependency from the container, the default value,
    // null when nullable — otherwise an exception naming the parameter.
    private function resolveParameters(ReflectionFunctionAbstract $function, array $parameters, ?string $context): array
    {
        $positional = array_values(array_filter($parameters, fn ($key) => is_int($key), ARRAY_FILTER_USE_KEY));
        $named = array_filter($parameters, fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);
        $cursor = 0;
        $arguments = [];

        foreach ($function->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            $class = $this->classOf($type, $function);

            if ($parameter->isVariadic()) {
                array_push($arguments, ...array_slice($positional, $cursor));

                break;
            }

            if (array_key_exists($name, $named)) {
                $arguments[] = $named[$name];

                continue;
            }

            if ($cursor < count($positional)) {
                $value = $positional[$cursor];

                if ($class === null || $value instanceof $class || ($value === null && $type !== null && $type->allowsNull())) {
                    $arguments[] = $value;
                    $cursor++;

                    continue;
                }
            }

            if ($class !== null) {
                $implementation = $this->contextual[$context ?? ''][$class] ?? null;

                if ($implementation instanceof Closure) {
                    $arguments[] = $implementation($this);

                    continue;
                }

                try {
                    $arguments[] = $this->make($implementation ?? $class);

                    continue;
                } catch (ContainerException $e) {
                    if (!$parameter->isDefaultValueAvailable() && !$type->allowsNull()) {
                        throw $e;
                    }
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            if ($type !== null && $type->allowsNull()) {
                $arguments[] = null;

                continue;
            }

            throw new ContainerException(sprintf(
                'Unresolvable parameter [$%s] in %s().',
                $name,
                $function instanceof ReflectionMethod ? $function->class . '::' . $function->name : $function->name
            ));
        }

        return $arguments;
    }

    private function classOf(?ReflectionType $type, ReflectionFunctionAbstract $function): ?string
    {
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        if (($name === 'self' || $name === 'static') && $function instanceof ReflectionMethod) {
            return $function->getDeclaringClass()->getName();
        }

        return $name;
    }

    private function normalize(string $abstract): string
    {
        $abstract = ltrim($abstract, '\\');
        $seen = [];

        while (isset($this->aliases[$abstract]) && !isset($seen[$abstract])) {
            $seen[$abstract] = true;
            $abstract = $this->aliases[$abstract];
        }

        return $abstract;
    }
}
