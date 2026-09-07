<?php

// Authorization. An ability is answered either by a policy method for the
// model class of the first argument (PostPolicy::update($user, $post)) or
// by a standalone gate definition (gate_define('admin', fn ($user) => ...)).
//
//   can('update', $post);  authorize('delete', $post);  $user->can('admin');
//   {if 'update'|can:$post} ... {/if}
//   get('/admin', ..., ['can:admin']);   get('/posts/{id}/edit', ..., ['can:update,Post@id']);
//
// Guests reach a callback only if its first parameter accepts null
// (?User $user); otherwise the ability is denied for them.

final class Gate
{
    private array $abilities = [];
    private array $policies = [];
    private array $beforeCallbacks = [];
    private array $afterCallbacks = [];
    private $userResolver;
    private bool $userFixed = false;
    private ?Model $fixedUser = null;

    public function __construct(?callable $userResolver = null)
    {
        $this->userResolver = $userResolver ?? fn () => null;
    }

    // ------------------------------------------------------------ setup --

    public function define(string $ability, callable|string $callback): static
    {
        $this->abilities[$ability] = $callback;

        return $this;
    }

    public function policy(string $model, string $policy): static
    {
        $this->policies[ltrim($model, '\\')] = $policy;

        return $this;
    }

    // Runs before every check; a non-null result decides the ability.
    public function before(callable $callback): static
    {
        $this->beforeCallbacks[] = $callback;

        return $this;
    }

    // Runs after every check; decides only when nothing else did.
    public function after(callable $callback): static
    {
        $this->afterCallbacks[] = $callback;

        return $this;
    }

    public function has(string $ability): bool
    {
        return isset($this->abilities[$ability]);
    }

    public function abilities(): array
    {
        return $this->abilities;
    }

    public function policies(): array
    {
        return $this->policies;
    }

    // A gate that checks on behalf of $user instead of the logged-in user.
    public function forUser(?Model $user): static
    {
        $gate = clone $this;
        $gate->userFixed = true;
        $gate->fixedUser = $user;

        return $gate;
    }

    public function resolveUser(): ?Model
    {
        return $this->userFixed ? $this->fixedUser : ($this->userResolver)();
    }

    // ----------------------------------------------------------- checks --

    public function allows(string $ability, mixed ...$arguments): bool
    {
        return $this->raw($ability, $arguments) === true;
    }

    public function denies(string $ability, mixed ...$arguments): bool
    {
        return !$this->allows($ability, ...$arguments);
    }

    // All of the abilities.
    public function check(string|array $abilities, mixed ...$arguments): bool
    {
        foreach ((array) $abilities as $ability) {
            if (!$this->allows($ability, ...$arguments)) {
                return false;
            }
        }

        return true;
    }

    // Any of the abilities.
    public function any(string|array $abilities, mixed ...$arguments): bool
    {
        foreach ((array) $abilities as $ability) {
            if ($this->allows($ability, ...$arguments)) {
                return true;
            }
        }

        return false;
    }

    public function none(string|array $abilities, mixed ...$arguments): bool
    {
        return !$this->any($abilities, ...$arguments);
    }

    public function authorize(string $ability, mixed ...$arguments): void
    {
        if (!$this->allows($ability, ...$arguments)) {
            throw new AuthorizationException();
        }
    }

    // The policy instance for a model (instance or class name), or null.
    // "<Model>Policy" in policies/ is found by convention.
    public function getPolicyFor(object|string $subject): ?object
    {
        $class = is_object($subject) ? get_class($subject) : ltrim($subject, '\\');

        if (!class_exists($class)) {
            return null;
        }

        foreach ([$class, ...array_values(class_parents($class) ?: [])] as $candidate) {
            if (isset($this->policies[$candidate])) {
                return app()->make($this->policies[$candidate]);
            }
        }

        $guess = class_basename($class) . 'Policy';

        if (is_subclass_of($class, Model::class) && class_exists($guess)) {
            $this->policies[$class] = $guess;

            return app()->make($guess);
        }

        return null;
    }

    // -------------------------------------------------------- internals --

    private function raw(string $ability, array $arguments): bool
    {
        $user = $this->resolveUser();

        foreach ($this->beforeCallbacks as $callback) {
            if (!$this->callableAcceptsUser($user, $callback)) {
                continue;
            }

            $result = app()->call($callback, [$user, $ability, $arguments]);

            if ($result !== null) {
                return (bool) $result;
            }
        }

        $result = $this->callAuthCallback($user, $ability, $arguments);

        foreach ($this->afterCallbacks as $callback) {
            if (!$this->callableAcceptsUser($user, $callback)) {
                continue;
            }

            $after = app()->call($callback, [$user, $ability, $result, $arguments]);
            $result ??= $after === null ? null : (bool) $after;
        }

        return $result === true;
    }

    private function callAuthCallback(?Model $user, string $ability, array $arguments): ?bool
    {
        if (isset($arguments[0]) && (is_object($arguments[0]) || is_string($arguments[0]))) {
            $policy = $this->getPolicyFor($arguments[0]);

            if ($policy !== null) {
                return $this->callPolicy($policy, $user, $ability, $arguments);
            }
        }

        if (!isset($this->abilities[$ability])) {
            return null;
        }

        $callback = $this->abilities[$ability];

        if (!$this->callableAcceptsUser($user, $callback)) {
            return false;
        }

        $result = app()->call($callback, [$user, ...$arguments]);

        return $result === null ? null : (bool) $result;
    }

    private function callPolicy(object $policy, ?Model $user, string $ability, array $arguments): ?bool
    {
        if (method_exists($policy, 'before') && $this->callableAcceptsUser($user, [$policy, 'before'])) {
            $result = app()->call([$policy, 'before'], [$user, $ability]);

            if ($result !== null) {
                return (bool) $result;
            }
        }

        $method = str_contains($ability, '-') ? str_camel($ability) : $ability;

        if (!is_callable([$policy, $method])) {
            return null;
        }

        if (!$this->callableAcceptsUser($user, [$policy, $method])) {
            return false;
        }

        $result = app()->call([$policy, $method], [$user, ...$arguments]);

        return $result === null ? null : (bool) $result;
    }

    // Guests may only reach callbacks whose first parameter accepts null.
    private function callableAcceptsUser(?Model $user, callable|array|string $callback): bool
    {
        if ($user !== null) {
            return true;
        }

        [, $reflection] = app()->reflect($callback);
        $parameters = $reflection->getParameters();

        if ($parameters === []) {
            return true;
        }

        $type = $parameters[0]->getType();

        return $type === null || $type->allowsNull() || $parameters[0]->isOptional();
    }
}
