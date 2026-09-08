<?php

test('middleware wraps the destination onion-style', function () {
    $log = [];

    middleware('a', function (callable $next) use (&$log) {
        $log[] = 'a-in';
        $result = $next();
        $log[] = 'a-out';

        return $result;
    });

    middleware('b', function (callable $next) use (&$log) {
        $log[] = 'b-in';
        $result = $next();
        $log[] = 'b-out';

        return $result;
    });

    $result = run_middleware(['a', 'b'], function () use (&$log) {
        $log[] = 'core';

        return 'done';
    });

    assert_same('done', $result);
    assert_same(['a-in', 'b-in', 'core', 'b-out', 'a-out'], $log);
});

test('parameters after a colon are passed to the handler', function () {
    middleware('limit', fn (callable $next, $max, $unit = 'min') => "$max/$unit:" . $next());

    assert_same('5/hour:x', run_middleware(['limit:5,hour'], fn () => 'x'));
    assert_same('3/min:x', run_middleware(['limit:3'], fn () => 'x'));
});

test('a middleware can short-circuit the pipeline', function () {
    $reached = false;

    middleware('deny', fn (callable $next) => 'denied');

    $result = run_middleware(['deny'], function () use (&$reached) {
        $reached = true;

        return 'secret';
    });

    assert_same('denied', $result);
    assert_false($reached);
});

test('unknown middleware fails fast', function () {
    assert_throws(fn () => run_middleware(['ghost'], fn () => ''), RuntimeException::class, 'Unknown middleware');
    assert_false(middleware_exists('ghost'));
    assert_true(middleware_exists('csrf'));
});

test('global middleware runs before route middleware', function () {
    $log = [];

    middleware('g', function (callable $next) use (&$log) {
        $log[] = 'g';

        return $next();
    });

    middleware('r', function (callable $next) use (&$log) {
        $log[] = 'r';

        return $next();
    });

    global_middleware(['g']);
    get('/x', fn () => 'x', null, ['r']);

    assert_same('x', route_dispatch('GET', '/x'));
    assert_same(['g', 'r'], $log);

    add_global_middleware('r');
    assert_same(['g', 'r'], global_middleware());
});

test('rate limiter blocks after max attempts within the window', function () {
    $key = 'test-' . bin2hex(random_bytes(4));

    for ($i = 1; $i <= 3; $i++) {
        $result = rate_limit_attempt($key, 3, 60);
        assert_true($result['allowed'], "attempt $i should be allowed");
        assert_same(3 - $i, $result['remaining']);
    }

    $result = rate_limit_attempt($key, 3, 60);
    assert_false($result['allowed']);
    assert_true($result['retry_after'] >= 1);

    rate_limit_clear($key);
    assert_true(rate_limit_attempt($key, 3, 60)['allowed']);
    rate_limit_clear($key);
});

test('throttle middleware answers 429 with a Retry-After header', function () {
    $_SERVER['REQUEST_URI'] = '/throttle-' . bin2hex(random_bytes(3));
    $path = request_path();

    global_middleware([]);
    get($path, fn () => 'ok', null, ['throttle:2,1']);

    assert_same('ok', route_dispatch('GET', $path));
    assert_same('ok', route_dispatch('GET', $path));

    $e = assert_throws(fn () => route_dispatch('GET', $path), HttpException::class);
    assert_same(429, $e->status);
    assert_key_exists('Retry-After', $e->headers);

    rate_limit_clear(request_ip() . '|GET|' . $path);
});
