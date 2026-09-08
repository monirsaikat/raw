<?php

// Runs jobs inline the moment they are pushed. The default in development
// and the simplest way to keep code working before a worker exists. Delays
// are ignored; a failing job calls failed() and rethrows so the error is
// visible in the request that dispatched it.

final class SyncQueue implements QueueDriver
{
    public function push(string $queue, string $payload, int $delaySeconds = 0): string
    {
        $id = 'sync-' . bin2hex(random_bytes(6));
        $job = (new QueuedJob($id, $queue, $payload))->job();

        try {
            $job->handle();
        } catch (Throwable $e) {
            $job->failed($e);

            throw $e;
        }

        return $id;
    }

    public function pop(string $queue): ?QueuedJob
    {
        return null;
    }

    public function release(QueuedJob $job, int $delaySeconds = 0): void
    {
    }

    public function delete(QueuedJob $job): void
    {
    }

    public function size(string $queue): int
    {
        return 0;
    }
}
