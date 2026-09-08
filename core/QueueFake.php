<?php

// Test double installed by Queue::fake(): records pushes instead of running
// or storing anything, so a test can assert what was dispatched without a
// worker or a jobs table.

final class QueueFake implements QueueDriver
{
    private array $pushed = [];

    public function push(string $queue, string $payload, int $delaySeconds = 0): string
    {
        $id = 'fake-' . (count($this->pushed) + 1);
        $this->pushed[] = [
            'id' => $id,
            'queue' => $queue,
            'delay' => $delaySeconds,
            'job' => (new QueuedJob($id, $queue, $payload))->job(),
        ];

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
        return count(array_filter($this->pushed, fn ($p) => $p['queue'] === $queue));
    }

    // Recorded pushes as [['id', 'queue', 'delay', 'job' => Job], ...],
    // optionally limited to one job class.
    public function pushed(?string $class = null): array
    {
        return array_values(array_filter(
            $this->pushed,
            fn ($p) => $class === null || $p['job'] instanceof $class
        ));
    }
}
