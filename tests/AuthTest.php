<?php

// Database-free auth tests: middleware behaviour and session state only.

test('auth middleware redirects guests to login and remembers the intended URL', function () {
    get('/login', fn () => 'login form', 'login');
    get('/', fn () => 'home', 'home');
    get('/account', fn () => 'secret', 'account', ['auth']);

    $_SERVER['REQUEST_URI'] = '/account';
    $response = dispatch('GET', '/account');

    assert_true($response instanceof Response);
    assert_same(302, $response->status);
    assert_same('/login', $response->headers['Location']);
    assert_same('http://localhost/account', session_get('_intended'));
});

test('auth middleware answers 401 JSON for API clients', function () {
    get('/login', fn () => '', 'login');
    get('/api/me', fn () => 'me', null, ['auth']);
    $_SERVER['HTTP_ACCEPT'] = 'application/json';

    $e = assert_throws(fn () => dispatch('GET', '/api/me'), HttpException::class);
    assert_same(401, $e->status);
});

test('guest middleware sends logged-in users home', function () {
    get('/', fn () => 'home', 'home');
    get('/login', fn () => 'login form', 'login', ['guest']);

    assert_same('login form', dispatch('GET', '/login'));

    session_set('auth_id', 1);
    assert_true(auth_check());
    assert_same(1, auth_id());

    $response = dispatch('GET', '/login');
    assert_same('/', $response->headers['Location']);
});

test('auth_intended prefers a stored local URL and falls back to the default', function () {
    get('/', fn () => '', 'home');
    get('/account', fn () => '', 'account');

    session_set('_intended', '/somewhere');
    assert_same('/somewhere', auth_intended()->headers['Location']);
    assert_false(session_has('_intended'), 'consumed');

    session_set('_intended', 'http://evil.com/');
    assert_same('/account', auth_intended('/account')->headers['Location']);
    assert_same('/', auth_intended()->headers['Location']);
});

test('token hashing is keyed and constant', function () {
    config_set('app.key', 'k1');
    $a = auth_token_hash('token');
    assert_same($a, auth_token_hash('token'));

    config_set('app.key', 'k2');
    assert_true($a !== auth_token_hash('token'));
});
