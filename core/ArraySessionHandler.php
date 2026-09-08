<?php

// In-memory session store: data lives for the current process only. Used
// by tests and by stateless endpoints that must never persist a session.

class ArraySessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private static array $sessions = [];

    public static function flush(): void
    {
        self::$sessions = [];
    }

    public static function all(): array
    {
        return self::$sessions;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        return self::$sessions[$id]['data'] ?? '';
    }

    public function write(string $id, string $data): bool
    {
        self::$sessions[$id] = ['data' => $data, 'time' => time()];

        return true;
    }

    public function destroy(string $id): bool
    {
        unset(self::$sessions[$id]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $removed = 0;

        foreach (self::$sessions as $id => $session) {
            if ($session['time'] < time() - $max_lifetime) {
                unset(self::$sessions[$id]);
                $removed++;
            }
        }

        return $removed;
    }

    public function validateId(string $id): bool
    {
        return isset(self::$sessions[$id]);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }
}
