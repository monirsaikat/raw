<?php

// Queue: sync driver, database driver on in-memory SQLite (never MySQL),
// worker retry/failure lifecycle, failed-job storage, Queue::fake().

class QtRecordingJob extends Job
{
    public static array $ran = [];

    public function __construct(public string $label = 'x')
    {
    }

    public function handle(): void
    {
        self::$ran[] = $this->label;
    }
}

class QtFlakyJob extends Job
{
    public static int $failUntilAttempt = 2;
    public static array $failedWith = [];
    public int $tries = 3;
    public int $backoff = 0;

    public function handle(): void
    {
        $count = Database::table('qt_attempts')->count();
        Database::table('qt_attempts')->insert(['n' => $count + 1]);

        if ($count + 1 < self::$failUntilAttempt) {
            throw new RuntimeException('not yet (attempt ' . ($count + 1) . ')');
        }
    }

    public function failed(Throwable $e): void
    {
        self::$failedWith[] = $e->getMessage();
    }
}

class QtAlwaysFailsJob extends Job
{
    public int $tries = 2;
    public static array $failedWith = [];

    public function handle(): void
    {
        throw new LogicException('boom');
    }

    public function failed(Throwable $e): void
    {
        self::$failedWith[] = $e->getMessage();
    }
}

function qt_database(): void
{
    use_test_database();

    if (!Schema::hasTable('qt_attempts')) {
        Schema::create('qt_attempts', function (Blueprint $t) {
            $t->id();
            $t->integer('n');
        });
    }

    Database::table('jobs')->delete();
    Database::table('failed_jobs')->delete();
    Database::table('qt_attempts')->delete();
    config_set('queue.default', 'database');
}

before_each(function () {
    Queue::reset();
    QtRecordingJob::$ran = [];
    QtFlakyJob::$failedWith = [];
    QtFlakyJob::$failUntilAttempt = 2;
    QtAlwaysFailsJob::$failedWith = [];
});

// ------------------------------------------------------------------ sync --

test('sync driver runs the job immediately and is the default', function () {
    assert_same('sync', Queue::defaultConnection());
    assert_instance_of(SyncQueue::class, Queue::connection());

    $id = dispatch(new QtRecordingJob('now'));

    assert_true(str_starts_with($id, 'sync-'));
    assert_same(['now'], QtRecordingJob::$ran);
});

test('sync driver calls failed() and rethrows', function () {
    try {
        dispatch(new QtAlwaysFailsJob());
        fail('expected the exception to propagate');
    } catch (LogicException $e) {
        assert_same('boom', $e->getMessage());
    }

    assert_same(['boom'], QtAlwaysFailsJob::$failedWith);
});

test('dispatch_sync runs inline even when the default is database', function () {
    config_set('queue.default', 'database');

    dispatch_sync(new QtRecordingJob('inline'));

    assert_same(['inline'], QtRecordingJob::$ran);
});

test('closures cannot be queued and say so', function () {
    try {
        dispatch(fn () => null);
        fail('expected an exception');
    } catch (InvalidArgumentException $e) {
        assert_contains('Closures cannot be queued', $e->getMessage());
    }
});

test('job fluent setters', function () {
    $job = (new QtRecordingJob())->onQueue('emails')->delay(30);

    assert_same('emails', $job->queue);
    assert_same(30, $job->delay);
    assert_same(3, $job->tries);
});

test('unknown connection or driver throws', function () {
    try {
        Queue::connection('nope');
        fail('expected an exception');
    } catch (InvalidArgumentException $e) {
        assert_contains('[nope]', $e->getMessage());
    }
});

// -------------------------------------------------------------- database --

test('database driver stores, pops and deletes jobs', function () {
    qt_database();

    $id = dispatch(new QtRecordingJob('db'));

    assert_same([], QtRecordingJob::$ran, 'must not run inline');
    assert_database_has('jobs', ['id' => $id, 'queue' => 'default', 'attempts' => 0]);
    assert_same(1, Queue::size());

    $driver = Queue::connection();
    $queued = $driver->pop('default');

    assert_not_null($queued);
    assert_same((string) $id, $queued->id);
    assert_same(1, $queued->attempts);
    assert_same(QtRecordingJob::class, $queued->jobClass());
    assert_null($driver->pop('default'), 'reserved job must not be handed out twice');

    $driver->delete($queued);
    assert_database_count('jobs', 0);
});

test('delayed jobs are invisible until available_at', function () {
    qt_database();

    dispatch_later(3600, new QtRecordingJob('later'));
    dispatch((new QtRecordingJob('other'))->onQueue('other'));

    assert_same(1, Queue::size('default'));
    assert_null(Queue::connection()->pop('default'));
    assert_not_null(Queue::connection()->pop('other'));
});

test('worker processes jobs in order and stops when empty', function () {
    qt_database();

    dispatch(new QtRecordingJob('a'));
    dispatch(new QtRecordingJob('b'));

    $worker = new Worker(Queue::connection(), 'database');
    $processed = $worker->work('default', ['stop_when_empty' => true, 'sleep' => 0]);

    assert_same(2, $processed);
    assert_same(['a', 'b'], QtRecordingJob::$ran);
    assert_database_count('jobs', 0);
    assert_database_count('failed_jobs', 0);
});

test('worker --once processes a single job', function () {
    qt_database();

    dispatch(new QtRecordingJob('a'));
    dispatch(new QtRecordingJob('b'));

    $processed = (new Worker(Queue::connection()))->work('default', ['once' => true]);

    assert_same(1, $processed);
    assert_same(1, Queue::size());
});

test('worker releases a failed job and retries until it succeeds', function () {
    qt_database();
    QtFlakyJob::$failUntilAttempt = 2;

    dispatch(new QtFlakyJob());
    $worker = new Worker(Queue::connection());

    $worker->work('default', ['once' => true]);
    assert_database_has('jobs', ['attempts' => 1], 'released back with attempts recorded');
    assert_same(1, Queue::size());

    $worker->work('default', ['once' => true]);
    assert_database_count('jobs', 0);
    assert_database_count('failed_jobs', 0);
    assert_same(2, Database::table('qt_attempts')->count());
    assert_same([], QtFlakyJob::$failedWith);
});

test('worker moves an exhausted job to failed_jobs and calls failed()', function () {
    qt_database();

    dispatch(new QtAlwaysFailsJob());
    $worker = new Worker(Queue::connection(), 'database');

    $worker->work('default', ['stop_when_empty' => true, 'sleep' => 0]);

    assert_database_count('jobs', 0);
    assert_database_count('failed_jobs', 1);
    assert_same(['boom'], QtAlwaysFailsJob::$failedWith, 'failed() runs once, after the last try');

    $failed = FailedJobs::all()[0];
    assert_same('database', $failed['connection']);
    assert_same('default', $failed['queue']);
    assert_contains('LogicException: boom', $failed['exception']);
});

test('backoff delays the retry', function () {
    qt_database();

    $job = new QtFlakyJob();
    $job->backoff = 600;
    dispatch($job);

    (new Worker(Queue::connection()))->work('default', ['once' => true]);

    $row = Database::table('jobs')->first();
    assert_true($row['available_at'] >= time() + 590, 'available_at pushed into the future');
    assert_null(Queue::connection()->pop('default'));
});

test('abandoned reservations expire after retry_after', function () {
    qt_database();
    config_set('queue.connections.database.retry_after', 60);

    $id = dispatch(new QtRecordingJob());
    Database::table('jobs')->where('id', $id)->update(['reserved_at' => time() - 120, 'attempts' => 1]);

    $queued = Queue::connection()->pop('default');
    assert_not_null($queued, 'stale reservation should be handed out again');
    assert_same(2, $queued->attempts);
});

test('corrupt payload goes straight to failed_jobs', function () {
    qt_database();

    Database::table('jobs')->insert([
        'queue' => 'default', 'payload' => 'O:12:"MissingClass":0:{}', 'attempts' => 0,
        'reserved_at' => null, 'available_at' => 0, 'created_at' => time(),
    ]);

    (new Worker(Queue::connection()))->work('default', ['once' => true]);

    assert_database_count('jobs', 0);
    assert_database_count('failed_jobs', 1);
});

test('failed jobs can be retried, forgotten and flushed', function () {
    qt_database();

    dispatch(new QtAlwaysFailsJob());
    dispatch(new QtAlwaysFailsJob());
    (new Worker(Queue::connection(), 'database'))->work('default', ['stop_when_empty' => true, 'sleep' => 0]);

    $failed = FailedJobs::all();
    assert_count(2, $failed);

    $newId = FailedJobs::retry($failed[0]['id']);
    assert_not_null($newId);
    assert_database_count('jobs', 1);
    assert_database_count('failed_jobs', 1);
    assert_null(FailedJobs::retry(999999));

    assert_true(FailedJobs::forget($failed[1]['id']));
    assert_false(FailedJobs::forget($failed[1]['id']));
    assert_database_count('failed_jobs', 0);

    dispatch(new QtAlwaysFailsJob());
    (new Worker(Queue::connection(), 'database'))->work('default', ['stop_when_empty' => true, 'sleep' => 0]);
    assert_same(2, FailedJobs::flush(), 'retried job failed again plus the new one');
});

test('worker honours max_jobs', function () {
    qt_database();

    foreach (['a', 'b', 'c'] as $l) {
        dispatch(new QtRecordingJob($l));
    }

    $processed = (new Worker(Queue::connection()))->work('default', ['max_jobs' => 2, 'sleep' => 0]);

    assert_same(2, $processed);
    assert_same(1, Queue::size());
});

// ------------------------------------------------------------------ fake --

test('Queue::fake captures pushes without running them', function () {
    Queue::fake();

    dispatch(new QtRecordingJob('one'));
    dispatch_later(10, (new QtRecordingJob('two'))->onQueue('emails'));

    assert_same([], QtRecordingJob::$ran);
    assert_count(2, Queue::pushed());
    assert_count(2, Queue::pushed(QtRecordingJob::class));
    assert_count(0, Queue::pushed(QtAlwaysFailsJob::class));

    Queue::assertPushed(QtRecordingJob::class);
    Queue::assertPushed(QtRecordingJob::class, fn (QtRecordingJob $job, string $queue, int $delay) => $job->label === 'two' && $queue === 'emails' && $delay === 10);
    Queue::assertNotPushed(QtAlwaysFailsJob::class);
    assert_same(2, Queue::size('emails') + Queue::size('default'));
});

test('Queue::assertPushed fails with a TestFailure', function () {
    Queue::fake();

    try {
        Queue::assertPushed(QtRecordingJob::class);
        fail('expected assertion to fail');
    } catch (TestFailure $e) {
        assert_contains('QtRecordingJob', $e->getMessage());
    }

    Queue::assertNothingPushed();
});

test('assertions without fake throw a LogicException', function () {
    try {
        Queue::assertPushed(QtRecordingJob::class);
        fail('expected an exception');
    } catch (LogicException $e) {
        assert_contains('Queue::fake()', $e->getMessage());
    }
});
