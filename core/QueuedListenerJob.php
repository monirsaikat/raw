<?php

// Wraps a queueable listener so the queue module runs it later. Only
// referenced when class Job exists (see events_queue_available()), so the
// autoloader never pulls this file in without the queue module.

class QueuedListenerJob extends Job
{
    public function __construct(
        public string $listener,
        public string $method,
        public string $event,
        public array $payload = []
    ) {
    }

    public function handle(): void
    {
        app()->make($this->listener)->{$this->method}(...$this->payload);
    }
}
