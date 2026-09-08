<?php

// Storage for jobs that exhausted their tries: the `failed_jobs` table.
// Kept apart from the drivers because every connection (even a future
// Redis one) shares the same failure log, and the console commands need
// to list, retry and prune it without knowing which driver produced it.

final class FailedJobs
{
    public static function record(string $connection, QueuedJob $job, Throwable $e): string
    {
        return self::query()->insertGetId([
            'connection' => $connection,
            'queue' => $job->queue,
            'payload' => $job->payload,
            'exception' => get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
            'failed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function all(): array
    {
        return self::query()->orderBy('id')->get()->all();
    }

    public static function find(string|int $id): ?array
    {
        return self::query()->where('id', $id)->first();
    }

    public static function forget(string|int $id): bool
    {
        return self::query()->where('id', $id)->delete() > 0;
    }

    public static function flush(): int
    {
        return self::query()->delete();
    }

    // Pushes the stored payload back onto its original connection and
    // queue, then removes the failed record. Returns the new job id.
    public static function retry(string|int $id): ?string
    {
        $row = self::find($id);

        if ($row === null) {
            return null;
        }

        $newId = Queue::connection($row['connection'] ?: null)->push((string) $row['queue'], (string) $row['payload']);
        self::forget($id);

        return $newId;
    }

    private static function query(): QueryBuilder
    {
        return Database::table(
            (string) config('queue.failed.table', 'failed_jobs'),
            config('queue.failed.connection')
        );
    }
}
