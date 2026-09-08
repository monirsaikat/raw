<?php

// Sessions in a database table (see the create_sessions_table migration and
// `php console.php session:table`). Rows carry the user id, IP and user
// agent next to the payload so "active sessions" screens and "log out
// everywhere" are a query away. validateId() backs PHP's strict mode: an
// unknown ID sent by the client is replaced, never adopted.

class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(
        private string $table = 'sessions',
        private ?string $connection = null,
        private int $lifetime = 7200
    ) {
    }

    private function query(): QueryBuilder
    {
        return Database::table($this->table, $this->connection);
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
        $row = $this->query()->where('id', $id)->first();

        if ($row === null) {
            return '';
        }

        // Expired rows that GC has not collected yet count as absent.
        if ((int) $row['last_activity'] < time() - $this->lifetime) {
            return '';
        }

        $payload = base64_decode((string) $row['payload'], true);

        return $payload === false ? '' : $payload;
    }

    public function write(string $id, string $data): bool
    {
        $row = [
            'payload' => base64_encode($data),
            'last_activity' => time(),
            'user_id' => $this->current_user_id(),
            'ip_address' => PHP_SAPI === 'cli' ? null : substr(request_ip(), 0, 45),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
        ];

        if ($this->query()->where('id', $id)->exists()) {
            $this->query()->where('id', $id)->update($row);
        } else {
            $this->query()->insert(['id' => $id] + $row);
        }

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->query()->where('id', $id)->delete();

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->query()->where('last_activity', '<', time() - $max_lifetime)->delete();
    }

    public function validateId(string $id): bool
    {
        return $this->query()->where('id', $id)->exists();
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->query()->where('id', $id)->update(['last_activity' => time()]);

        return true;
    }

    // Read straight from $_SESSION: the session is being written, so the
    // session_*() helpers must not be re-entered here.
    private function current_user_id(): int|string|null
    {
        $id = $_SESSION[auth_session_key()] ?? null;

        return is_int($id) || is_string($id) ? $id : null;
    }
}
