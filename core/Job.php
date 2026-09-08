<?php

// Base class for queued work. A job is serialized when dispatched and
// unserialized by the worker, so everything handle() needs must live in
// properties (constructor promotion is the natural fit). Tuning knobs are
// public properties so a subclass can override them with a one-liner.

abstract class Job
{
    // How many times the worker attempts the job before moving it to failed_jobs.
    public int $tries = 3;

    // Seconds to wait before retrying after a failure.
    public int $backoff = 0;

    // Queue name; null means the connection's default queue.
    public ?string $queue = null;

    // Seconds to hold the job before a worker may pick it up.
    public int $delay = 0;

    abstract public function handle(): void;

    // Called once, after the final attempt failed (or immediately on the
    // sync driver). Override to notify, clean up or compensate.
    public function failed(Throwable $e): void
    {
    }

    public function onQueue(?string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }

    public function delay(int $seconds): static
    {
        $this->delay = max(0, $seconds);

        return $this;
    }

    // Short name for logs and the queue:failed table.
    public function displayName(): string
    {
        return static::class;
    }
}
