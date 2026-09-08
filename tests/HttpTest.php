<?php

test('request merges query and body, body wins, cookies are excluded', function () {
    $_GET = ['a' => '1', 'b' => 'q'];
    $_POST = ['b' => 'p'];
    $_COOKIE = ['c' => 'cookie'];

    assert_same(['a' => '1', 'b' => 'p'], request());
    assert_same('p', request('b'));
    assert_same('q', query('b'));
    assert_null(request('c'));
    assert_same('d', request('zzz', 'd'));
});

test('input trims strings recursively', function () {
    $_POST = ['name' => '  Ann ', 'tags' => [' a ', 'b'], 'n' => 5];

    assert_same('Ann', input('name'));
    assert_same(['a', 'b'], input('tags'));
    assert_same(['name' => 'Ann', 'tags' => ['a', 'b'], 'n' => 5], input());
    assert_same('dflt', input('missing', 'dflt'));
});

test('input_only, input_except and has_input', function () {
    $_POST = ['a' => '1', 'b' => ' ', 'c' => '3'];

    assert_same(['a' => '1', 'c' => '3'], input_only(['a', 'c', 'zzz']));
    assert_same(['b' => ''], input_except(['a', 'c']));
    assert_true(has_input('a'));
    assert_false(has_input('b'), 'whitespace-only is empty');
    assert_false(has_input('zzz'));
});

test('method spoofing only applies to POST with a known verb', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['_method'] = 'delete';
    assert_same('DELETE', request_method());
    assert_same('POST', request_real_method());

    $_POST['_method'] = 'HACK';
    assert_same('POST', request_method());

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST['_method'] = 'DELETE';
    assert_same('GET', request_method());
});

test('request_path strips the base path and trailing slashes', function () {
    $_SERVER['REQUEST_URI'] = '/saikat/test1/about/?x=1';
    config_set('app.base_path', '/saikat/test1');
    assert_same('/about', request_path());
    assert_same('/saikat/test1/about/', request_uri());

    config_set('app.base_path', null);
    $_SERVER['REQUEST_URI'] = '/';
    assert_same('/', request_path());

    $_SERVER['REQUEST_URI'] = '/users//';
    assert_same('/users', request_path());
});

test('wants_json looks at Accept and JSON request bodies', function () {
    assert_false(wants_json());

    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    assert_true(wants_json());

    $_SERVER['HTTP_ACCEPT'] = 'text/html,*/*';
    assert_false(wants_json());

    unset($_SERVER['HTTP_ACCEPT']);
    $_SERVER['CONTENT_TYPE'] = 'application/json; charset=utf-8';
    assert_true(request_is_json());
    assert_true(wants_json());
});

test('request headers and ajax detection', function () {
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_SERVER['HTTP_X_CUSTOM_THING'] = 'yes';

    assert_true(request_is_ajax());
    assert_same('yes', request_header('X-Custom-Thing'));
    assert_same('no', request_header('X-Missing', 'no'));
});

test('request_host rejects injected values and secure detection works', function () {
    $_SERVER['HTTP_HOST'] = 'example.com:8080';
    assert_same('example.com:8080', request_host());

    $_SERVER['HTTP_HOST'] = "evil.com\r\nX-Injected: 1";
    assert_same('localhost', request_host());

    assert_false(request_is_secure());
    $_SERVER['HTTPS'] = 'on';
    assert_true(request_is_secure());
});

test('response helpers build Response objects', function () {
    $r = redirect('/x');
    assert_same(302, $r->status);
    assert_same('/x', $r->headers['Location']);
    assert_same('', $r->body);

    $r = redirect('/y', 301);
    assert_same(301, $r->status);

    $j = json(['a' => 1, 'u' => 'é/']);
    assert_same('{"a":1,"u":"é/"}', $j->body);
    assert_same('application/json; charset=utf-8', $j->headers['Content-Type']);

    $r = response('hi', 201, ['x-custom' => 'y']);
    assert_same(201, $r->status);
    assert_same('y', $r->headers['X-Custom'], 'header names are normalised');
    assert_same('hi', (string) $r);

    assert_same('{"k":"v"}', response(['k' => 'v'])->body);
    assert_same($r, response($r), 'responses pass through');
});

test('redirect_route builds the URL from a named route', function () {
    get('/user/{id}', fn () => '', 'user');

    assert_same('/user/9', redirect_route('user', ['id' => 9])->headers['Location']);
});

test('back prefers a same-host referer, then the session, then the fallback', function () {
    $_SERVER['HTTP_HOST'] = 'example.com';

    $_SERVER['HTTP_REFERER'] = 'http://example.com/form';
    assert_same('http://example.com/form', back()->headers['Location']);

    $_SERVER['HTTP_REFERER'] = 'http://evil.com/form';
    session_set('_previous_url', 'http://example.com/prev');
    assert_same('http://example.com/prev', back()->headers['Location']);

    session_forget('_previous_url');
    assert_same('/', back()->headers['Location']);
    assert_same('/fallback', back('/fallback')->headers['Location']);
});

test('url_is_local rejects protocol-relative, foreign and scheme-only URLs', function () {
    $_SERVER['HTTP_HOST'] = 'example.com';

    assert_true(url_is_local('/ok'));
    assert_true(url_is_local('http://example.com/ok'));
    assert_true(url_is_local('http://EXAMPLE.com/ok'));
    assert_false(url_is_local('//evil.com'));
    assert_false(url_is_local('http://evil.com/'));
    assert_false(url_is_local('javascript:alert(1)'));
    assert_false(url_is_local("/ok\r\nLocation: evil"));
});

test('csrf tokens verify from the body or the header', function () {
    $token = csrf_token();

    assert_same($token, csrf_token(), 'stable within a session');
    assert_false(csrf_verify());

    $_POST['_token'] = $token;
    assert_true(csrf_verify());

    $_POST['_token'] = 'nope';
    assert_false(csrf_verify());

    unset($_POST['_token']);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
    assert_true(csrf_verify());

    assert_contains('name="_token" value="' . $token . '"', csrf_field());
    assert_contains('name="csrf-token" content="' . $token . '"', csrf_meta());
});

test('csrf middleware blocks unsafe methods without a token', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    post('/x', fn () => 'posted');
    get('/x', fn () => 'got');

    $e = assert_throws(fn () => route_dispatch('POST', '/x'), HttpException::class);
    assert_same(419, $e->status);

    $_POST['_token'] = csrf_token();
    assert_same('posted', route_dispatch('POST', '/x'));

    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_POST['_token']);
    assert_same('got', route_dispatch('GET', '/x'));
});

test('send_response serialises arrays and models as JSON and echoes strings', function () {
    ob_start();
    send_response(['a' => 1]);
    assert_same('{"a":1}', ob_get_clean());

    ob_start();
    send_response('plain');
    assert_same('plain', ob_get_clean());

    ob_start();
    send_response(null);
    assert_same('', ob_get_clean());

    ob_start();
    send_response(ModelTestUserForHttp::hydrate(['id' => 1, 'secret' => 's']));
    assert_same('{"id":1}', ob_get_clean());
});

class ModelTestUserForHttp extends Model
{
    protected static array $hidden = ['secret'];
}
