<?php

// Pulls jobs off a driver and runs them, one at a time, until told to stop.
// Retry policy lives on the Job (tries/backoff); the worker enforces it,
// moves exhausted jobs to failed_jobs and calls failed(). Everything is
// wrapped in Throwable catches so one bad job never kills the loop.

final class Worker
{
    private bool $shouldQuit = false;

    public function __construct(
        private QueueDriver $driver,
        private string $connectionName = 'database',
        private ?Closure $output = null,
    ) {
    }

    // Options: once, stop_when_empty, sleep (seconds), max_jobs, max_time
    // (seconds), memory (MB). $queue may be comma-separated, highest
    // priority first. Returns the number of jobs processed.
    public function work(string $queue, array $options = []): int
    {
        $once = (bool) ($options['once'] ?? false);
        $stopWhenEmpty = (bool) ($options['stop_when_empty'] ?? false);
        $sleep = max(0, (int) ($options['sleep'] ?? 3));
        $maxJobs = (int) ($options['max_jobs'] ?? 0);
        $maxTime = (int) ($options['max_time'] ?? 0);
        $memory = (int) ($options['memory'] ?? 128);
        $queues = array_values(array_filter(array_map('trim', explode(',', $queue))));
        $started = time();
        $processed = 0;

        $this->listenForSignals();

        while (!$this->shouldQuit) {
            $queued = null;
            $queuedOn = '';

            foreach ($queues as $name) {
                $queued = $this->driver->pop($name);

                if ($queued !== null) {
                    $queuedOn = $name;
                    break;
                }
            }

            if ($queued !== null) {
                $this->process($queued, $queuedOn);
                $processed++;
            } elseif ($once || $stopWhenEmpty) {
                break;
            } elseif ($sleep > 0) {
                sleep($sleep);
            }

            if ($once
                || ($maxJobs > 0 && $processed >= $maxJobs)
                || ($maxTime > 0 && time() - $started >= $maxTime)
                || ($memory > 0 && memory_get_usage(true) / 1024 / 1024 >= $memory)) {
                break;
            }
        }

        return $processed;
    }

    // Runs one reserved job through the full try/retry/fail lifecycle.
    public function process(QueuedJob $queued, string $queue): void
    {
        $class = $queued->jobClass();
        $start = microtime(true);

        try {
            $job = $queued->job();
        } catch (Throwable $e) {
            // Unserializable payloads can never succeed: fail immediately.
            $this->fail($queued, null, $e);

            return;
        }

        try {
            $this->say("Processing: $class [{$queued->id}] attempt {$queued->attempts}/{$job->tries}");
            $job->handle();
            $this->driver->delete($queued);
            $this->say(sprintf('Processed:  %s [%s] in %d ms', $class, $queued->id, (microtime(true) - $start) * 1000));
            log_info("queue: processed $class", ['id' => $queued->id, 'queue' => $queue]);
        } catch (Throwable $e) {
            if ($queued->attempts >= max(1, $job->tries)) {
                $this->fail($queued, $job, $e);

                return;
            }

            $this->driver->release($queued, $job->backoff);
            $this->say("Released:   $class [{$queued->id}] retry in {$job->backoff}s: " . $e->getMessage());
            log_warning("queue: released $class", ['id' => $queued->id, 'attempt' => $queued->attempts, 'error' => $e->getMessage()]);
        }
    }

    public function stop(): void
    {
        $this->shouldQuit = true;
    }

    private function fail(QueuedJob $queued, ?Job $job, Throwable $e): void
    {
        $class = $queued->jobClass();

        try {
            FailedJobs::record($this->connectionName, $queued, $e);
        } catch (Throwable $storeError) {
            log_error('queue: could not store failed job', ['id' => $queued->id, 'error' => $storeError->getMessage()]);
        }

        $this->driver->delete($queued);
        $this->say("Failed:     $class [{$queued->id}]: " . $e->getMessage());
        log_error("queue: failed $class", ['id' => $queued->id, 'exception' => get_class($e), 'error' => $e->getMessage()]);

        if ($job !== null) {
            try {
                $job->failed($e);
            } catch (Throwable $hookError) {
                log_error("queue: failed() hook threw for $class", ['error' => $hookError->getMessage()]);
            }
        }
    }

    // SIGTERM/SIGINT finish the current job, then exit the loop. Only
    // available with ext-pcntl (Linux/macOS); harmless elsewhere.
    private function listenForSignals(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop());
        pcntl_signal(SIGINT, fn () => $this->stop());
    }

    private function say(string $message): void
    {
        if ($this->output !== null) {
            ($this->output)($message);
        }
    }
}
