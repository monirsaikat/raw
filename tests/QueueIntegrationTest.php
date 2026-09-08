<?php

// The seams between modules: a queueable listener becomes a QueuedListenerJob,
// Mail::queue() becomes a SendMailJob, and both jobs do their work when a
// worker runs them. Everything stays in-process (sync driver / Queue::fake()).

class QiWelcomeListener
{
    public bool $shouldQueue = true;
    public static array $seen = [];

    public function handle(string $name): void
    {
        self::$seen[] = $name;
    }
}

class QiInlineListener
{
    public static array $seen = [];

    public function handle(string $name): void
    {
        self::$seen[] = $name;
    }
}

class QiNoteMail extends Mailable
{
    public function __construct(public string $who)
    {
    }

    public function build(): void
    {
        $this->to($this->who . '@example.com')->subject('Note for ' . $this->who)->text('Hello ' . $this->who);
    }
}

before_each(function () {
    forget_listeners();
    Queue::reset();
    Mail::restore();
    QiWelcomeListener::$seen = [];
    QiInlineListener::$seen = [];
    config_set('queue.default', 'sync');
});

test('a queueable listener is pushed as a QueuedListenerJob instead of running inline', function () {
    Queue::fake();
    listen('user.registered', 'QiWelcomeListener');
    listen('user.registered', 'QiInlineListener');

    event('user.registered', 'ada');

    assert_same(['ada'], QiInlineListener::$seen, 'plain listeners still run inline');
    assert_same([], QiWelcomeListener::$seen, 'queued listener did not run yet');
    Queue::assertPushed('QueuedListenerJob', fn (QueuedListenerJob $job) => $job->listener === 'QiWelcomeListener' && $job->payload === ['ada']);
});

test('the sync driver runs a queued listener immediately', function () {
    listen('user.registered', 'QiWelcomeListener');

    event('user.registered', 'grace');

    assert_same(['grace'], QiWelcomeListener::$seen);
});

test('a QueuedListenerJob survives serialization and calls the listener when handled', function () {
    $job = unserialize(serialize(new QueuedListenerJob('QiWelcomeListener', 'handle', 'user.registered', ['linus'])));

    $job->handle();

    assert_same(['linus'], QiWelcomeListener::$seen);
});

test('Mail::queue() pushes a SendMailJob carrying the mailable', function () {
    Queue::fake();

    Mail::queue(new QiNoteMail('ada'));

    Queue::assertPushed('SendMailJob', fn (SendMailJob $job) => $job->mailable instanceof QiNoteMail && $job->mailable->who === 'ada');
});

test('a SendMailJob sends the mail through the configured mailer when handled', function () {
    Mail::fake();
    $job = unserialize(serialize(new SendMailJob(new QiNoteMail('grace'))));

    $job->handle();

    Mail::assertSent('QiNoteMail', fn (QiNoteMail $m) => $m->who === 'grace');
});

test('Mail::queue() on the sync driver delivers right away', function () {
    Mail::fake();

    Mail::queue(new QiNoteMail('linus'));

    Mail::assertSent('QiNoteMail');
});
