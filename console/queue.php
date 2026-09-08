<?php

// Queue commands: run a worker, inspect and retry failed jobs, scaffold a
// job class. Loaded by console.php after the built-in commands.

// Resolves the connection named by --connection (or the default) and
// refuses the sync driver, which has no queue to work.
function queue_worker_from_options(array $options): ?array
{
    $name = (string) ($options['connection'] ?? Queue::defaultConnection());
    $driver = Queue::connection($name);

    if ($driver instanceof SyncQueue) {
        error_line("Connection [$name] uses the sync driver; there is nothing to work. Set QUEUE_CONNECTION=database or pass --connection=database.");

        return null;
    }

    return [$name, $driver];
}

function queue_work_command(array $args): int
{
    [$positional, $options] = parse_arguments($args);
    $resolved = queue_worker_from_options($options);

    if ($resolved === null) {
        return 1;
    }

    [$name, $driver] = $resolved;
    $queue = (string) ($positional[0] ?? $options['queue'] ?? Queue::defaultQueue($name));
    $worker = new Worker($driver, $name, fn (string $m) => line('[' . date('H:i:s') . "] $m"));

    line("Working queue [$queue] on connection [$name]. Press Ctrl+C to stop.");

    $processed = $worker->work($queue, [
        'once' => isset($options['once']),
        'stop_when_empty' => isset($options['stop-when-empty']),
        'sleep' => (int) ($options['sleep'] ?? 3),
        'max_jobs' => (int) ($options['max-jobs'] ?? 0),
        'max_time' => (int) ($options['max-time'] ?? 0),
        'memory' => (int) ($options['memory'] ?? 128),
    ]);

    line("Stopped after $processed job(s).");

    return 0;
}

command('queue:work', 'Process queued jobs [queue] [--once] [--stop-when-empty] [--sleep=3] [--max-jobs=N] [--max-time=S] [--memory=128] [--connection=database]', 'queue_work_command');
command('queue:listen', 'Alias of queue:work', 'queue_work_command');

command('queue:size', 'Number of jobs waiting [queue] [--connection=]', function (array $args) {
    [$positional, $options] = parse_arguments($args);
    $name = (string) ($options['connection'] ?? Queue::defaultConnection());
    $queue = (string) ($positional[0] ?? Queue::defaultQueue($name));

    line((string) Queue::size($queue, $name));

    return 0;
});

command('queue:failed', 'List failed jobs', function () {
    $rows = [];

    foreach (FailedJobs::all() as $row) {
        $rows[] = [
            $row['id'],
            $row['connection'],
            $row['queue'],
            (new QueuedJob((string) $row['id'], (string) $row['queue'], (string) $row['payload']))->jobClass(),
            $row['failed_at'],
            strtok((string) $row['exception'], "\n"),
        ];
    }

    if ($rows === []) {
        line('No failed jobs.');

        return 0;
    }

    print_table(['ID', 'Connection', 'Queue', 'Job', 'Failed at', 'Error'], $rows);

    return 0;
});

command('queue:retry', 'Push failed jobs back onto their queue [id|all]', function (array $args) {
    [$positional] = parse_arguments($args);
    $target = (string) ($positional[0] ?? '');

    if ($target === '') {
        error_line('Usage: php console.php queue:retry <id|all>');

        return 1;
    }

    $ids = $target === 'all' ? array_column(FailedJobs::all(), 'id') : [$target];

    if ($ids === []) {
        line('No failed jobs to retry.');

        return 0;
    }

    $status = 0;

    foreach ($ids as $id) {
        $newId = FailedJobs::retry($id);

        if ($newId === null) {
            error_line("Failed job [$id] not found.");
            $status = 1;
        } else {
            line("Retried failed job [$id] as [$newId].");
        }
    }

    return $status;
});

command('queue:forget', 'Delete a failed job [id]', function (array $args) {
    [$positional] = parse_arguments($args);
    $id = (string) ($positional[0] ?? '');

    if ($id === '') {
        error_line('Usage: php console.php queue:forget <id>');

        return 1;
    }

    if (!FailedJobs::forget($id)) {
        error_line("Failed job [$id] not found.");

        return 1;
    }

    line("Deleted failed job [$id].");

    return 0;
});

command('queue:flush', 'Delete every failed job [--jobs also clears the pending jobs table]', function (array $args) {
    [, $options] = parse_arguments($args);
    $count = FailedJobs::flush();

    line("Deleted $count failed job(s).");

    if (isset($options['jobs'])) {
        $driver = Queue::connection((string) ($options['connection'] ?? 'database'));

        if ($driver instanceof DatabaseQueue) {
            line('Deleted ' . $driver->clear() . ' pending job(s).');
        }
    }

    return 0;
});

command('make:job', 'Create a job class [name, e.g. SendWelcomeEmail]', function (array $args) {
    [$positional] = parse_arguments($args);
    $name = str_studly(trim((string) ($positional[0] ?? '')));

    if ($name === '') {
        error_line('Usage: php console.php make:job SendWelcomeEmail');

        return 1;
    }

    return write_stub(BASE_PATH . '/jobs/' . $name . '.php', <<<PHP
        <?php

        // Dispatch with: dispatch(new $name(\$data));
        // Everything handle() needs must be a property: the job is serialized
        // when queued and rebuilt by the worker.

        class $name extends Job
        {
            public int \$tries = 3;
            public int \$backoff = 0;

            public function __construct()
            {
            }

            public function handle(): void
            {
                //
            }

            // Called after the last failed attempt.
            public function failed(Throwable \$e): void
            {
            }
        }

        PHP) ? 0 : 1;
});
