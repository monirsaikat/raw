<?php

// Event dispatcher. listen('user.registered', fn ($user) => ...) registers a
// listener; event('user.registered', $user) runs every listener that matches
// (wildcards such as 'user.*' included) in priority order and returns their
// results. Object events use the class name and travel as the only argument.
//
// Listeners may be closures, 'ListenerClass' (handle() via the container),
// 'ListenerClass@method', or any callable. A class implementing ShouldQueue
// or declaring public bool $shouldQueue = true is pushed to the queue module
// when it is installed (core/modules/20-queue.php); otherwise it runs inline.

$GLOBALS['__event_listeners'] = [];

function listen(string $event, callable|string $listener, int $priority = 0): void
{
    $GLOBALS['__event_listeners'][$event][$priority][] = $listener;
}

// Registered listeners for an event, highest priority first, wildcards included.
function listeners(string $event): array
{
    $matched = [];

    foreach ($GLOBALS['__event_listeners'] as $pattern => $byPriority) {
        if (!event_matches((string) $pattern, $event)) {
            continue;
        }

        foreach ($byPriority as $priority => $listeners) {
            foreach ($listeners as $listener) {
                $matched[] = [$priority, $listener];
            }
        }
    }

    // usort is stable since PHP 8: equal priorities keep registration order.
    usort($matched, fn ($a, $b) => $b[0] <=> $a[0]);

    return array_column($matched, 1);
}

function has_listeners(string $event): bool
{
    return listeners($event) !== [];
}

// Drops every listener, or only those registered under one exact pattern.
function forget_listeners(?string $event = null): void
{
    if ($event === null) {
        $GLOBALS['__event_listeners'] = [];

        return;
    }

    unset($GLOBALS['__event_listeners'][$event]);
}

// 'user.*' matches 'user.registered' and 'user.profile.updated'; '*' matches all.
function event_matches(string $pattern, string $event): bool
{
    if ($pattern === $event || $pattern === '*') {
        return true;
    }

    if (!str_contains($pattern, '*')) {
        return false;
    }

    $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';

    return (bool) preg_match($regex, $event);
}

function event_name(string|object $event): string
{
    return is_object($event) ? get_class($event) : $event;
}

// Fires an event and returns every listener's result (in call order).
function event(string|object $event, mixed ...$payload): array
{
    $name = event_name($event);

    if (is_object($event)) {
        $payload = [$event];
    }

    if (Event::isFaked()) {
        Event::record($name, $payload);

        return [];
    }

    $results = [];

    foreach (listeners($name) as $listener) {
        $results[] = call_listener($listener, $name, $payload);
    }

    return $results;
}

// Like event() but stops at the first listener returning a non-null value.
function event_until(string|object $event, mixed ...$payload): mixed
{
    $name = event_name($event);

    if (is_object($event)) {
        $payload = [$event];
    }

    if (Event::isFaked()) {
        Event::record($name, $payload);

        return null;
    }

    foreach (listeners($name) as $listener) {
        $result = call_listener($listener, $name, $payload);

        if ($result !== null) {
            return $result;
        }
    }

    return null;
}

// Runs one listener. Class-name listeners are resolved through the container
// so constructor dependencies are injected; queueable ones are dispatched.
function call_listener(callable|string $listener, string $event, array $payload): mixed
{
    if (is_string($listener) && !function_exists($listener)) {
        [$class, $method] = array_pad(explode('@', $listener, 2), 2, 'handle');

        if (!class_exists($class)) {
            throw new InvalidArgumentException("Listener class [$class] not found for event [$event].");
        }

        if (listener_should_queue($class) && events_queue_available()) {
            dispatch(new QueuedListenerJob($class, $method, $event, $payload));

            return null;
        }

        return app()->make($class)->{$method}(...$payload);
    }

    return $listener(...$payload);
}

function listener_should_queue(string $class): bool
{
    if (is_subclass_of($class, 'ShouldQueue')) {
        return true;
    }

    // Public bool $shouldQueue = true on the listener class, no interface needed.
    $defaults = (new ReflectionClass($class))->getDefaultProperties();

    return ($defaults['shouldQueue'] ?? false) === true;
}

// True when the queue module is installed (class Job and dispatch(Job)).
function events_queue_available(): bool
{
    return class_exists('Job') && function_exists('dispatch');
}

// Registers config/events.php: ['user.registered' => ['SendWelcomeMail', ...]].
function events_boot(): void
{
    if (!is_file(BASE_PATH . '/config/events.php')) {
        return;
    }

    foreach ((array) config('events', []) as $event => $listeners) {
        foreach ((array) $listeners as $listener) {
            listen((string) $event, $listener);
        }
    }
}

events_boot();
