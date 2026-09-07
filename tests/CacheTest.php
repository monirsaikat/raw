<?php

test('cache stores and retrieves serialisable values', function () {
    $key = 'test:' . bin2hex(random_bytes(4));

    assert_false(cache_has($key));
    assert_same('dflt', cache_get($key, 'dflt'));

    cache_set($key, ['a' => 1, 'b' => [true, null]], 60);

    assert_true(cache_has($key));
    assert_same(['a' => 1, 'b' => [true, null]], cache_get($key));

    cache_forget($key);
    assert_false(cache_has($key));
});

test('expired entries are treated as misses', function () {
    $key = 'test:' . bin2hex(random_bytes(4));

    cache_set($key, 'v', 1);
    assert_same('v', cache_get($key));

    // Rewrite the entry with an expiry in the past.
    file_put_contents(cache_file($key), serialize(['expires' => time() - 1, 'value' => 'v']));

    assert_null(cache_get($key));
    assert_false(is_file(cache_file($key)), 'expired file is removed');
});

test('a TTL of zero never expires', function () {
    $key = 'test:' . bin2hex(random_bytes(4));

    cache_set($key, 'forever', 0);
    $entry = unserialize((string) file_get_contents(cache_file($key)));

    assert_same(0, $entry['expires']);
    assert_same('forever', cache_get($key));
    cache_forget($key);
});

test('cache_remember computes on a miss and reuses on a hit', function () {
    $key = 'test:' . bin2hex(random_bytes(4));
    $calls = 0;

    $compute = function () use (&$calls) {
        $calls++;

        return 'computed';
    };

    assert_same('computed', cache_remember($key, 60, $compute));
    assert_same('computed', cache_remember($key, 60, $compute));
    assert_same(1, $calls);

    cache_forget($key);
});

test('corrupt cache files are ignored', function () {
    $key = 'test:' . bin2hex(random_bytes(4));

    file_put_contents(cache_file($key), 'not serialized');

    assert_same('dflt', cache_get($key, 'dflt'));
    cache_forget($key);
});
