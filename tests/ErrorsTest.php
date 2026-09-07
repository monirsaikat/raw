<?php

test('abort throws an HttpException with status, message and headers', function () {
    $e = assert_throws(fn () => abort(403, 'No entry', ['X-Reason' => 'test']), HttpException::class);

    assert_same(403, $e->status);
    assert_same('No entry', $e->getMessage());
    assert_same(['X-Reason' => 'test'], $e->headers);

    abort_if(false, 500);
    abort_unless(true, 500);
    assert_throws(fn () => abort_if(true, 410), HttpException::class);
    assert_throws(fn () => abort_unless(false, 410), HttpException::class);
});

test('http_status_text knows common statuses', function () {
    assert_same('Not Found', http_status_text(404));
    assert_same('Page Expired', http_status_text(419));
    assert_same('Too Many Requests', http_status_text(429));
    assert_same('Error', http_status_text(999));
});

test('render_exception produces an HTML error page for an HttpException', function () {
    $response = render_exception(new HttpException(404));

    assert_same(404, $response->status);
    assert_contains('<h1>404</h1>', $response->body);
    assert_contains('Not Found', $response->body);

    $response = render_exception(new HttpException(405, '', ['Allow' => 'GET']));
    assert_same('GET', $response->headers['Allow']);
});

test('render_exception returns JSON for API clients', function () {
    $_SERVER['HTTP_ACCEPT'] = 'application/json';

    $response = render_exception(new HttpException(403, 'Nope'));

    assert_same(403, $response->status);
    assert_same('{"message":"Nope"}', $response->body);
    assert_same('application/json; charset=utf-8', $response->headers['Content-Type']);
});

test('unexpected exception messages are hidden unless debugging', function () {
    $_SERVER['HTTP_ACCEPT'] = 'application/json';

    $response = render_exception(new RuntimeException('secret detail'));

    assert_same(500, $response->status);

    if (APP_DEBUG) {
        assert_contains('secret detail', $response->body);
        assert_contains('"exception":"RuntimeException"', $response->body);
    } else {
        assert_not_contains('secret detail', $response->body);
        assert_same('{"message":"Internal Server Error"}', $response->body);
    }
});

test('validation exceptions redirect back with flashed errors and input', function () {
    $_SERVER['HTTP_REFERER'] = 'http://localhost/form';

    $response = render_exception(new ValidationException(['name' => ['Required']], ['name' => '']));

    assert_same(302, $response->status);
    assert_same('http://localhost/form', $response->headers['Location']);

    test_next_request();

    assert_same(['name' => ['Required']], flash('errors'));
    assert_same('', old('name'));
});

test('validation exceptions become 422 JSON for API clients', function () {
    $_SERVER['HTTP_ACCEPT'] = 'application/json';

    $response = render_exception(new ValidationException(['name' => ['Required']]));

    assert_same(422, $response->status);
    assert_same('{"message":"The given data was invalid.","errors":{"name":["Required"]}}', $response->body);
});

test('PHP warnings become exceptions, but @ still silences them', function () {
    $e = assert_throws(function () {
        $array = [];

        return $array['missing'];
    }, ErrorException::class);

    assert_contains('Undefined array key', $e->getMessage());

    $array = [];
    $value = @$array['missing'];
    assert_null($value);
});

test('the debug page shows the error but never trace arguments', function () {
    // Built at runtime so the literal never appears in the code excerpt;
    // it can only reach the page through the stack-trace arguments.
    $secret = implode('-', ['s3cr3t', 'password']);
    $e = null;

    (function ($argument) use (&$e) {
        $e = new RuntimeException('boom happened');
    })($secret);

    $html = debug_error_page($e);

    assert_contains('boom happened', $html);
    assert_contains('RuntimeException', $html);
    assert_contains('Stack trace', $html);
    assert_contains($secret, $e->getTraceAsString(), 'PHP itself records the argument');
    assert_not_contains($secret, $html);
});
