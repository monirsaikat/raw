<?php

// A job as it sits on a queue: the driver's id, the serialized payload and
// how many times it has been reserved. Drivers hand these to the Worker,
// which unserializes the Job lazily so a broken payload fails cleanly.

final class QueuedJob
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly string $payload,
        public readonly int $attempts = 1,
    ) {
    }

    public function job(): Job
    {
        $job = @unserialize($this->payload, ['allowed_classes' => true]);

        if (!$job instanceof Job) {
            throw new RuntimeException("Queued job [{$this->id}] does not unserialize to a Job; is its class still autoloadable?");
        }

        return $job;
    }

    // Class name without unserializing everything, for logs and listings.
    public function jobClass(): string
    {
        return preg_match('/^O:\d+:"([^"]+)"/', $this->payload, $m) ? $m[1] : 'unknown';
    }
}
