<?php

class CtLogger
{
    public array $lines = [];

    public function log(string $line): void
    {
        $this->lines[] = $line;
    }
}

class CtMailer
{
    public function __construct(public CtLogger $logger, public string $from = 'noreply@example.com')
    {
    }
}

class CtReport
{
    public function __construct(public CtMailer $mailer, public CtLogger $logger)
    {
    }
}

interface CtCache
{
    public function name(): string;
}

class CtArrayCache implements CtCache
{
    public function name(): string
    {
        return 'array';
    }
}

class CtFileCache implements CtCache
{
    public function name(): string
    {
        return 'file';
    }
}

class CtService
{
    public function __construct(public CtCache $cache)
    {
    }
}

class CtOtherService
{
    public function __construct(public CtCache $cache)
    {
    }
}

class CtNeedsScalar
{
    public function __construct(public int $count)
    {
    }
}

class CtCircularA
{
    public function __construct(CtCircularB $b)
    {
    }
}

class CtCircularB
{
    public function __construct(CtCircularA $a)
    {
    }
}

class CtInvokable
{
    public function __invoke(CtLogger $logger, string $suffix = '!'): string
    {
        return 'invoked' . $suffix;
    }
}

class CtController
{
    public function __construct(public CtLogger $logger)
    {
    }

    public function show(CtMailer $mailer, $id, string $tab = 'main'): string
    {
        return "show:$id:$tab:" . $mailer->from;
    }
}

class CtMiddleware
{
    public function __construct(private CtLogger $logger)
    {
    }

    public function handle(callable $next, string $tag = 'x'): string
    {
        $this->logger->log($tag);

        return '[' . $tag . ':' . $next() . ']';
    }
}

test('builds classes and their nested dependencies by reflection', function () {
    $report = app(CtReport::class);

    assert_instance_of(CtReport::class, $report);
    assert_instance_of(CtMailer::class, $report->mailer);
    assert_same('noreply@example.com', $report->mailer->from, 'scalar default used');
    assert_true($report->logger !== $report->mailer->logger, 'unbound classes are not shared');
});

test('singletons are shared, plain bindings are fresh', function () {
    app()->singleton(CtLogger::class);

    assert_same(app(CtLogger::class), app(CtLogger::class));

    $report = app(CtReport::class);
    assert_same($report->logger, $report->mailer->logger, 'the singleton is injected everywhere');

    app()->bind(CtMailer::class);
    assert_true(app(CtMailer::class) !== app(CtMailer::class));

    assert_true(app()->bound(CtLogger::class));
    assert_true(app()->resolved(CtLogger::class));
    assert_false(app()->bound(CtReport::class));
    assert_true(app()->has(CtReport::class));
    assert_false(app()->has('NoSuchClass'));

    app()->forget(CtLogger::class);
    assert_false(app()->bound(CtLogger::class));
});

test('interfaces resolve through bindings, closures, aliases and contextual bindings', function () {
    assert_throws(fn () => app(CtCache::class), ContainerException::class, 'not instantiable');

    app()->bind(CtCache::class, CtArrayCache::class);
    assert_same('array', app(CtService::class)->cache->name());

    app()->when(CtOtherService::class)->needs(CtCache::class)->give(CtFileCache::class);
    assert_same('file', app(CtOtherService::class)->cache->name());
    assert_same('array', app(CtService::class)->cache->name(), 'contextual binding is scoped to its consumer');

    app()->when(CtService::class)->needs(CtCache::class)->give(fn () => new CtFileCache());
    assert_same('file', app(CtService::class)->cache->name(), 'closure implementations work too');

    app()->singleton('cache.factory', fn (Container $container, array $parameters) => new CtFileCache());
    assert_instance_of(CtFileCache::class, app('cache.factory'));

    app()->alias('cache', CtCache::class);
    assert_instance_of(CtArrayCache::class, app('cache'));

    $object = new stdClass();
    app()->instance('config.object', $object);
    assert_same($object, app('config.object'));
});

test('make() accepts parameters by name and hands them to closures', function () {
    assert_same(3, app()->make(CtNeedsScalar::class, ['count' => 3])->count);

    app()->bind('greeting', fn (Container $container, array $parameters) => 'hi ' . ($parameters['name'] ?? 'there'));

    assert_same('hi Ann', app('greeting', ['name' => 'Ann']));
    assert_same('hi there', app('greeting'));
});

test('unresolvable and circular dependencies fail with clear messages', function () {
    assert_throws(fn () => app(CtNeedsScalar::class), ContainerException::class, '$count');
    assert_throws(fn () => app(CtCircularA::class), ContainerException::class, 'Circular');
    assert_throws(fn () => app('NoSuchClass'), ContainerException::class, 'does not exist');
});

test('call() injects dependencies and fills the rest by name or position', function () {
    $container = app();

    assert_same('show:5:main:noreply@example.com', $container->call([new CtController(new CtLogger()), 'show'], ['id' => 5]));
    assert_same('show:7:x:noreply@example.com', $container->call('CtController@show', [7, 'x']));
    assert_same('invoked!', $container->call(CtInvokable::class));
    assert_same('invoked?', $container->call(new CtInvokable(), ['suffix' => '?']));
    assert_same('a-b', $container->call(fn (string $a, ?CtLogger $logger, string $b = 'b') => $a . '-' . $b, ['a']));
    assert_same(3, $container->call(fn (int ...$numbers) => array_sum($numbers), [1, 2]));
    assert_same(3, $container->call('strlen', ['abc']));

    $e = assert_throws(fn () => $container->call(fn (string $required) => $required), ContainerException::class);
    assert_contains('$required', $e->getMessage());
});

test('positional objects satisfy class-typed parameters', function () {
    $logger = new CtLogger();

    $result = app()->call(fn (CtLogger $l, ?CtMailer $m, string $x) => [$l, $m, $x], [$logger, null, 'ok']);

    assert_same($logger, $result[0]);
    assert_null($result[1]);
    assert_same('ok', $result[2]);
});

test('config/container.php bindings are loaded by container_boot and flush() resets', function () {
    config_set('container.singletons', ['CtLogger' => 'CtLogger']);
    config_set('container.bindings', ['CtCache' => 'CtFileCache']);
    config_set('container.aliases', ['log' => 'CtLogger']);

    container_boot();

    assert_same(app('log'), app(CtLogger::class));
    assert_same('file', app(CtService::class)->cache->name());

    app()->flush();

    assert_false(app()->bound(CtLogger::class));
    assert_same(app(), app(Container::class));
});

test('controllers get constructor and method injection; optional segments keep defaults', function () {
    get('/show/{id}/{tab?}', 'CtController@show');

    assert_same('show:9:main:noreply@example.com', route_dispatch('GET', '/show/9'));
    assert_same('show:9:edit:noreply@example.com', route_dispatch('GET', '/show/9/edit'));
});

test('class-based middleware is built by the container', function () {
    app()->singleton(CtLogger::class);
    middleware('tagged', CtMiddleware::class);
    get('/m', fn () => 'core', null, ['tagged:one']);

    assert_same('[one:core]', route_dispatch('GET', '/m'));
    assert_same(['one'], app(CtLogger::class)->lines);
});
