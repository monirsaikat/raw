<?php

// Static entry point to the queue: resolves drivers from config/queue.php,
// serializes jobs, and swaps in a QueueFake for tests. Static because the
// framework has no service-provider layer; connections are memoised per
// process and dropped by reset() between tests.

final class Queue
{
    private static array $connections = [];
    private static ?QueueFake $fake = null;

    // The driver for a named connection (null = config 'queue.default').
    public static function connection(?string $name = null): QueueDriver
    {
        if (self::$fake !== null) {
            return self::$fake;
        }

        $name ??= self::defaultConnection();

        return self::$connections[$name] ??= self::resolve($name);
    }

    public static function defaultConnection(): string
    {
        return (string) config('queue.default', 'sync');
    }

    // Serializes and pushes a job; returns the driver's id. Closures cannot
    // be serialized without a third-party library, so they are rejected
    // with a clear message instead of failing deep inside serialize().
    public static function push(Job|Closure $job, ?string $connection = null): string
    {
        if ($job instanceof Closure) {
            throw new InvalidArgumentException('Closures cannot be queued: PHP cannot serialize them. Wrap the code in a Job class (php console.php make:job Name).');
        }

        $connection ??= self::defaultConnection();
        $queue = $job->queue ?? self::defaultQueue($connection);

        return self::connection($connection)->push($queue, serialize($job), $job->delay);
    }

    public static function later(int $seconds, Job $job, ?string $connection = null): string
    {
        return self::push($job->delay($seconds), $connection);
    }

    public static function size(?string $queue = null, ?string $connection = null): int
    {
        $connection ??= self::defaultConnection();

        return self::connection($connection)->size($queue ?? self::defaultQueue($connection));
    }

    public static function defaultQueue(?string $connection = null): string
    {
        $connection ??= self::defaultConnection();

        return (string) config("queue.connections.$connection.queue", 'default');
    }

    // ------------------------------------------------------------- testing --

    public static function fake(): QueueFake
    {
        return self::$fake = new QueueFake();
    }

    public static function isFaked(): bool
    {
        return self::$fake !== null;
    }

    public static function pushed(?string $class = null): array
    {
        return self::$fake?->pushed($class) ?? [];
    }

    public static function assertPushed(string $class, ?callable $filter = null): void
    {
        self::requireFake();

        foreach (self::pushed($class) as $push) {
            if ($filter === null || $filter($push['job'], $push['queue'], $push['delay'])) {
                return;
            }
        }

        self::failAssertion("Expected [$class] to be pushed" . ($filter !== null ? ' matching the filter' : '') . ', but it was not.');
    }

    public static function assertNotPushed(string $class): void
    {
        self::requireFake();

        if (self::pushed($class) !== []) {
            self::failAssertion("Did not expect [$class] to be pushed, but it was.");
        }
    }

    public static function assertNothingPushed(): void
    {
        self::requireFake();

        if (self::pushed() !== []) {
            self::failAssertion('Expected no jobs to be pushed, but ' . count(self::pushed()) . ' were.');
        }
    }

    // Forgets memoised drivers and any fake; tests call this in before_each
    // so each case sees fresh config and an empty fake.
    public static function reset(): void
    {
        self::$connections = [];
        self::$fake = null;
    }

    // ------------------------------------------------------------ internals --

    private static function resolve(string $name): QueueDriver
    {
        $config = config("queue.connections.$name");

        if (!is_array($config)) {
            throw new InvalidArgumentException("Queue connection [$name] is not configured in config/queue.php.");
        }

        return match ($config['driver'] ?? '') {
            'sync' => new SyncQueue(),
            'database' => new DatabaseQueue(
                $config['connection'] ?? null,
                (string) ($config['table'] ?? 'jobs'),
                (int) ($config['retry_after'] ?? 90),
            ),
            default => throw new InvalidArgumentException('Unsupported queue driver [' . ($config['driver'] ?? '') . "] on connection [$name]."),
        };
    }

    private static function requireFake(): void
    {
        if (self::$fake === null) {
            throw new LogicException('Call Queue::fake() before asserting on pushed jobs.');
        }
    }

    private static function failAssertion(string $message): never
    {
        if (class_exists('TestFailure', false)) {
            throw new TestFailure($message);
        }

        throw new RuntimeException($message);
    }
}
