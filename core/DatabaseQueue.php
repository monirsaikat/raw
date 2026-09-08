<?php

// Queue backed by the `jobs` table. Reservation happens inside a
// transaction with SELECT ... FOR UPDATE on MySQL plus a conditional UPDATE
// that only succeeds when nobody else grabbed the row, so several workers
// can share one table safely (SQLite ignores the lock but serialises
// writers anyway). Jobs reserved longer than retry_after seconds are
// treated as abandoned and handed out again.

final class DatabaseQueue implements QueueDriver
{
    public function __construct(
        private ?string $connection = null,
        private string $table = 'jobs',
        private int $retryAfter = 90,
    ) {
    }

    public function push(string $queue, string $payload, int $delaySeconds = 0): string
    {
        $now = time();

        return $this->query()->insertGetId([
            'queue' => $queue,
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now + max(0, $delaySeconds),
            'created_at' => $now,
        ]);
    }

    public function pop(string $queue): ?QueuedJob
    {
        $now = time();
        $expired = $now - $this->retryAfter;
        $available = function (QueryBuilder $q) use ($expired): void {
            $q->whereNull('reserved_at')->orWhere('reserved_at', '<=', $expired);
        };

        return Database::connection($this->connection)->transaction(function () use ($queue, $now, $available) {
            $row = $this->query()
                ->where('queue', $queue)
                ->where($available)
                ->where('available_at', '<=', $now)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return null;
            }

            $attempts = (int) $row['attempts'] + 1;
            $updated = $this->query()
                ->where('id', $row['id'])
                ->where($available)
                ->update(['reserved_at' => $now, 'attempts' => $attempts]);

            return $updated === 0 ? null : new QueuedJob((string) $row['id'], $queue, (string) $row['payload'], $attempts);
        });
    }

    public function release(QueuedJob $job, int $delaySeconds = 0): void
    {
        $this->query()->where('id', $job->id)->update([
            'reserved_at' => null,
            'available_at' => time() + max(0, $delaySeconds),
        ]);
    }

    public function delete(QueuedJob $job): void
    {
        $this->query()->where('id', $job->id)->delete();
    }

    public function size(string $queue): int
    {
        return $this->query()->where('queue', $queue)->count();
    }

    // Drops every job on a queue (or all queues); used by queue:flush --jobs.
    public function clear(?string $queue = null): int
    {
        $query = $this->query();

        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        return $query->delete();
    }

    private function query(): QueryBuilder
    {
        return Database::table($this->table, $this->connection);
    }
}
