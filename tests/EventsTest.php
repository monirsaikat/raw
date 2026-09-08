<?php

// Event dispatcher: listen(), event(), event_until(), wildcards, priorities,
// class listeners through the container, Event::fake() assertions.

class EventsTestListener
{
    public static array $seen = [];

    public function __construct(public Container $container)
    {
    }

    public function handle(string $name): string
    {
        self::$seen[] = $name;

        return 'handled:' . $name;
    }

    public function other(string $name): string
    {
        return 'other:' . $name;
    }
}

class EventsTestQueuedListener
{
    public bool $shouldQueue = true;

    public function handle(): string
    {
        return 'ran inline';
    }
}

class EventsTestUserRegistered
{
    public function __construct(public string $email)
    {
    }
}

function events_test_function_listener(string $name): string
{
    return 'fn:' . $name;
}

before_each(function () {
    forget_listeners();
    Event::restore();
    EventsTestListener::$seen = [];
});

after_each(function () {
    forget_listeners();
    Event::restore();
});

test('event() runs listeners and returns their results in priority order', function () {
    listen('user.registered', fn ($name) => 'low:' . $name, -5);
    listen('user.registered', fn ($name) => 'first:' . $name);
    listen('user.registered', fn ($name) => 'second:' . $name);
    listen('user.registered', fn ($name) => 'high:' . $name, 10);

    assert_same(['high:ann', 'first:ann', 'second:ann', 'low:ann'], event('user.registered', 'ann'));
    assert_count(4, listeners('user.registered'));
    assert_same([], event('nobody.listens'));
});

test('wildcard listeners match dotted event names', function () {
    listen('user.*', fn () => 'wild');
    listen('*', fn () => 'all');
    listen('user.registered', fn () => 'exact');

    assert_same(['wild', 'all', 'exact'], event('user.registered'));
    assert_same(['wild', 'all'], event('user.profile.updated'));
    assert_same(['all'], event('order.paid'));
    assert_true(has_listeners('user.anything'));
    assert_true(event_matches('user.*', 'user.x'));
    assert_false(event_matches('user.*', 'order.x'));
    assert_false(event_matches('user', 'user.x'));
});

test('object events use their class name and are passed as the single argument', function () {
    $received = null;
    listen('EventsTestUserRegistered', function (EventsTestUserRegistered $e) use (&$received) {
        $received = $e;

        return $e->email;
    });

    $event = new EventsTestUserRegistered('a@b.c');

    assert_same(['a@b.c'], event($event, 'ignored', 'payload'));
    assert_same($event, $received);
});

test('event_until() stops at the first non-null result', function () {
    $calls = 0;
    listen('check', function () use (&$calls) { $calls++; return null; });
    listen('check', function () use (&$calls) { $calls++; return 'stop'; });
    listen('check', function () use (&$calls) { $calls++; return 'never'; });

    assert_same('stop', event_until('check'));
    assert_same(2, $calls);
    assert_null(event_until('nothing'));
});

test('class listeners resolve through the container; Class@method and function names work', function () {
    listen('greet', 'EventsTestListener');
    listen('greet', 'EventsTestListener@other');
    listen('greet', 'events_test_function_listener');
    listen('greet', [new EventsTestListener(app()), 'other']);

    assert_same(['handled:bob', 'other:bob', 'fn:bob', 'other:bob'], event('greet', 'bob'));
    assert_same(['bob'], EventsTestListener::$seen);
});

test('unknown listener classes raise a clear error', function () {
    listen('x', 'NoSuchListenerClass');

    assert_throws(fn () => event('x'), InvalidArgumentException::class, 'NoSuchListenerClass');
});

test('queueable listeners run inline when the queue module is absent', function () {
    listen('job', 'EventsTestQueuedListener');

    assert_true(listener_should_queue('EventsTestQueuedListener'));
    assert_false(listener_should_queue('EventsTestListener'));

    if (events_queue_available()) {
        skip('queue module installed; listener would be dispatched');
    }

    assert_same(['ran inline'], event('job'));
});

test('forget_listeners() clears one pattern or everything', function () {
    listen('a', fn () => 1);
    listen('b', fn () => 2);

    forget_listeners('a');
    assert_false(has_listeners('a'));
    assert_true(has_listeners('b'));

    forget_listeners();
    assert_false(has_listeners('b'));
});

test('Event::fake() records dispatches without running listeners', function () {
    $ran = false;
    listen('user.registered', function () use (&$ran) { $ran = true; });

    Event::fake();
    assert_same([], event('user.registered', 'ann', 42));
    assert_null(event_until('user.registered', 'bob'));
    event(new EventsTestUserRegistered('x@y.z'));

    assert_false($ran);
    assert_count(3, Event::dispatched());
    assert_count(2, Event::dispatched('user.registered'));
    assert_count(2, Event::dispatched('user.*'));

    Event::assertDispatched('user.registered');
    Event::assertDispatched('user.registered', fn ($name, $id = null) => $name === 'ann' && $id === 42);
    Event::assertDispatched('EventsTestUserRegistered', fn (EventsTestUserRegistered $e) => $e->email === 'x@y.z');
    Event::assertNotDispatched('order.paid');
    Event::assertNotDispatched('user.registered', fn ($name) => $name === 'zed');

    assert_throws(fn () => Event::assertDispatched('order.paid'), TestFailure::class, 'not dispatched');
    assert_throws(fn () => Event::assertDispatched('user.registered', fn ($n) => $n === 'zed'), TestFailure::class, 'filter');
    assert_throws(fn () => Event::assertNotDispatched('user.registered'), TestFailure::class, 'unexpectedly');
    assert_throws(fn () => Event::assertNothingDispatched(), TestFailure::class);

    Event::restore();
    assert_false(Event::isFaked());
    event('user.registered', 'real');
    assert_true($ran);
    Event::assertNothingDispatched();
});

test('config/events.php listeners are registered at boot', function () {
    config_set('events', ['boot.test' => ['EventsTestListener', 'events_test_function_listener']]);
    events_boot();

    assert_same(['handled:x', 'fn:x'], event('boot.test', 'x'));
});
