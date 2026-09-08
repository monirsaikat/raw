<?php

// Test double for the event dispatcher. After Event::fake() nothing reaches
// the listeners; event() only records what was fired so a test can assert
// on it. Event::restore() switches the real dispatcher back on.

class Event
{
    private static bool $faked = false;
    private static array $dispatched = [];

    public static function fake(): void
    {
        self::$faked = true;
        self::$dispatched = [];
    }

    public static function restore(): void
    {
        self::$faked = false;
        self::$dispatched = [];
    }

    public static function isFaked(): bool
    {
        return self::$faked;
    }

    public static function record(string $event, array $payload): void
    {
        self::$dispatched[] = ['event' => $event, 'payload' => $payload];
    }

    // All recorded events, or only those matching a name/pattern.
    public static function dispatched(?string $event = null): array
    {
        if ($event === null) {
            return self::$dispatched;
        }

        return array_values(array_filter(
            self::$dispatched,
            fn ($entry) => event_matches($event, $entry['event'])
        ));
    }

    // The filter receives the payload arguments (the object for object events).
    public static function assertDispatched(string $event, ?callable $filter = null): void
    {
        foreach (self::dispatched($event) as $entry) {
            if ($filter === null || $filter(...$entry['payload'])) {
                return;
            }
        }

        self::fail(
            $filter === null
                ? "Event [$event] was not dispatched."
                : "Event [$event] was dispatched but no occurrence matched the filter."
        );
    }

    public static function assertNotDispatched(string $event, ?callable $filter = null): void
    {
        foreach (self::dispatched($event) as $entry) {
            if ($filter === null || $filter(...$entry['payload'])) {
                self::fail("Event [$event] was dispatched unexpectedly.");
            }
        }
    }

    public static function assertNothingDispatched(): void
    {
        if (self::$dispatched !== []) {
            self::fail(count(self::$dispatched) . ' event(s) were dispatched unexpectedly.');
        }
    }

    // TestFailure only exists once core/testing.php is loaded.
    private static function fail(string $message): never
    {
        throw class_exists('TestFailure') ? new TestFailure($message) : new RuntimeException($message);
    }
}
