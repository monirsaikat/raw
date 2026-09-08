<?php

// Signed URLs and the 'signed' middleware.

before_each(function () {
    config_set('app.key', 'test-signing-key');
    config_set('app.url', '');

    get('/unsubscribe/{id:\d+}', fn ($id) => "bye $id", 'unsubscribe', ['signed']);
    get('/open', fn () => 'open', 'open');
});

// Runs a GET request whose REQUEST_URI carries the given path and query.
function signed_test_get(string $urlOrPath): TestResponse
{
    $path = parse_url($urlOrPath, PHP_URL_PATH) ?? '/';
    $query = parse_url($urlOrPath, PHP_URL_QUERY);

    return http_get($path . ($query ? '?' . $query : ''));
}

test('signed_url builds an absolute URL with a signature and signed_path a relative one', function () {
    $url = signed_url('unsubscribe', ['id' => 5, 'from' => 'digest']);

    assert_matches('#^http://localhost/unsubscribe/5\?from=digest&signature=[0-9a-f]{64}$#', $url);
    assert_same(substr($url, strlen('http://localhost')), signed_path('unsubscribe', ['id' => 5, 'from' => 'digest']));
    assert_same($url, signed_url('unsubscribe', ['id' => 5, 'from' => 'digest']), 'deterministic without expiry');

    $temporary = signed_url('unsubscribe', ['id' => 5], 3600);
    assert_matches('#\?expires=\d+&signature=#', $temporary);
    assert_same($temporary, signed_url_temporary('unsubscribe', ['id' => 5], 3600));
});

test('signed_url respects APP_URL and the base path', function () {
    config_set('app.url', 'https://example.com:8443/myapp');
    config_set('app.base_path', '/myapp');

    $url = signed_url('unsubscribe', ['id' => 1]);

    assert_true(str_starts_with($url, 'https://example.com:8443/myapp/unsubscribe/1?signature='), $url);
    assert_true(signed_url_valid($url));
    assert_true(str_starts_with(signed_path('unsubscribe', ['id' => 1]), '/myapp/unsubscribe/1?'));
});

test('signed_url_valid accepts genuine URLs and rejects altered or unsigned ones', function () {
    $url = signed_url('unsubscribe', ['id' => 5, 'from' => 'digest']);

    assert_true(signed_url_valid($url));
    assert_false(signed_url_valid(str_replace('/5?', '/6?', $url)), 'changed path');
    assert_false(signed_url_valid(str_replace('digest', 'weekly', $url)), 'changed parameter');
    assert_false(signed_url_valid(substr($url, 0, -1) . '0'), 'changed signature');
    assert_false(signed_url_valid('http://localhost/unsubscribe/5'), 'unsigned');
    assert_false(signed_url_valid('http://localhost/unsubscribe/5?signature='));

    config_set('app.key', 'another-key');
    assert_false(signed_url_valid($url), 'other key');
});

test('the signature may sit anywhere in the query string', function () {
    $url = signed_url('unsubscribe', ['id' => 5, 'a' => '1']);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

    $reordered = 'http://localhost/unsubscribe/5?signature=' . $params['signature'] . '&a=1';

    assert_true(signed_url_valid($reordered));
});

test('expired links are rejected', function () {
    $fresh = signed_url('unsubscribe', ['id' => 5], 60);
    assert_true(signed_url_valid($fresh));

    $stale = signed_url('unsubscribe', ['id' => 5], -60);
    assert_false(signed_url_valid($stale));

    // Moving the expiry forward breaks the signature.
    $forged = preg_replace_callback('/expires=(\d+)/', fn ($m) => 'expires=' . ($m[1] + 86400), $stale);
    assert_false(signed_url_valid($forged));
});

test('signed middleware lets valid links through and answers 403 otherwise', function () {
    signed_test_get(signed_url('unsubscribe', ['id' => 7]))->assertOk()->assertSee('bye 7');

    signed_test_get('/unsubscribe/7')->assertForbidden();
    signed_test_get('/unsubscribe/7?signature=abc')->assertForbidden();
    signed_test_get(signed_url('unsubscribe', ['id' => 7], -1))->assertForbidden();

    signed_test_get('/open')->assertOk();
});

test('signed_url_valid reads the current request by default', function () {
    $url = signed_url('unsubscribe', ['id' => 3]);
    $_SERVER['REQUEST_URI'] = substr($url, strlen('http://localhost'));

    assert_true(signed_url_valid());

    $_SERVER['REQUEST_URI'] = '/unsubscribe/3';
    assert_false(signed_url_valid());
});
