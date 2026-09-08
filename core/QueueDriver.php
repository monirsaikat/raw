<?php

// Contract every queue backend implements. Payloads are opaque strings
// (serialized Job objects) so drivers never need to know about Job itself.

interface QueueDriver
{
    // Stores a payload and returns the driver's id for it.
    public function push(string $queue, string $payload, int $delaySeconds = 0): string;

    // Reserves the next available job, or null when the queue is empty.
    public function pop(string $queue): ?QueuedJob;

    // Puts a reserved job back so it can be retried after $delaySeconds.
    public function release(QueuedJob $job, int $delaySeconds = 0): void;

    // Removes a reserved job for good (it finished or was moved to failed_jobs).
    public function delete(QueuedJob $job): void;

    // Number of jobs waiting (including delayed ones) on a queue.
    public function size(string $queue): int;
}
