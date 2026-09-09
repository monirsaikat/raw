<?php

// Debug toolbar (core/Toolbar.php). The toolbar is off under the CLI SAPI
// unless a test forces it on, so every case starts with toolbar_enable(true).

function toolbar_test_page(): Response
{
    return new Response("<html><head></head><body>\n<p>Hello</p>\n</body></html>");
}

before_each(function () {
    toolbar_enable(true);
    $GLOBALS['__log_buffer'] = [];
});

after_each(function () {
    toolbar_enable(false);
    unset($GLOBALS['__log_buffer']);
});

test('the toolbar stays off under the CLI unless a test forces it', function () {
    toolbar_enable(false);

    $response = toolbar_test_page();
    toolbar_inject($response);

    assert_not_contains('cf-toolbar', $response->body);
});

test('the panel is injected before </body> with the CSP nonce and request facts', function () {
    get('/hello/{name}', fn ($name) => 'hi ' . $name, 'hello', ['throttle:5,1']);
    http_get('/hello/ann');

    $response = toolbar_test_page();
    toolbar_inject($response);

    assert_contains('id="cf-toolbar"', $response->body);
    assert_contains('<script nonce="' . csp_nonce() . '">', $response->body);
    assert_contains('<style nonce="' . csp_nonce() . '">', $response->body);
    assert_matches('#id="cf-toolbar".*</body></html>$#s', $response->body);
    assert_same(1, substr_count($response->body, '</body>'));
    assert_contains('GET /hello/ann → 200', $response->body);
    assert_contains('/hello/{name} (hello)', $response->body);
    assert_contains('csrf, throttle:5,1', $response->body);
    assert_contains('{&quot;name&quot;:&quot;ann&quot;}', $response->body);
    assert_contains('guest', $response->body);

    $data = toolbar_collect($response);
    assert_same('hello', $data['route']['name']);
    assert_same(['name' => 'ann'], $data['route']['parameters']);
    assert_true($data['files'] > 10);
    assert_true($data['time_ms'] > 0);
});

test('JSON, redirects, errors, non-HTML and pages without </body> are left alone', function () {
    $json = json(['ok' => true]);
    toolbar_inject($json);
    assert_same('{"ok":true}', $json->body);

    $redirect = redirect('/somewhere');
    toolbar_inject($redirect);
    assert_same('', $redirect->body);

    $error = new Response('<html><body>Nope</body></html>', 404);
    toolbar_inject($error);
    assert_not_contains('cf-toolbar', $error->body);

    $text = new Response('<body></body>', 200, ['Content-Type' => 'text/plain']);
    toolbar_inject($text);
    assert_same('<body></body>', $text->body);

    $fragment = new Response('<p>partial</p>');
    toolbar_inject($fragment);
    assert_same('<p>partial</p>', $fragment->body);

    $explicit = new Response('<html><body></body></html>', 201, ['Content-Type' => 'text/html; charset=utf-8']);
    toolbar_inject($explicit);
    assert_contains('cf-toolbar', $explicit->body);
});

test('?_toolbar=0, toolbar_disable() and config app.toolbar switch it off', function () {
    $_GET['_toolbar'] = '0';
    $response = toolbar_test_page();
    toolbar_inject($response);
    assert_not_contains('cf-toolbar', $response->body);
    unset($_GET['_toolbar']);

    toolbar_disable();
    $response = toolbar_test_page();
    toolbar_inject($response);
    assert_not_contains('cf-toolbar', $response->body);

    toolbar_enable(true);
    config_set('app.toolbar', false);
    $response = toolbar_test_page();
    toolbar_inject($response);
    assert_not_contains('cf-toolbar', $response->body);

    config_set('app.toolbar', true);
    $response = toolbar_test_page();
    toolbar_inject($response);
    assert_contains('cf-toolbar', $response->body);
});

test('session secrets are hidden, values truncated, and the user id shown', function () {
    session_set('_csrf_token', 'super-secret-token-value');
    session_set('cart', ['apples' => 3]);
    session_set('note', str_repeat('x', 200));

    $data = toolbar_collect(toolbar_test_page());

    assert_same('(hidden)', $data['session']['_csrf_token']);
    assert_same('{"apples":3}', $data['session']['cart']);
    assert_true(strlen($data['session']['note']) <= 63);   // str_limit(60) plus '...'

    $html = toolbar_render($data);
    assert_not_contains('super-secret-token-value', $html);
    assert_contains('apples', $html);

    session_set(auth_session_key(), 42);
    $html = toolbar_render(toolbar_collect(toolbar_test_page()));
    assert_contains('#42', $html);
});

test('queries and log lines emitted during the request are listed', function () {
    use_test_database();
    Database::enableQueryLog();
    Database::flushQueryLog();
    Database::table('users')->where('email', 'nobody@example.com')->count();

    log_info('Toolbar saw {thing}', ['thing' => 'this line', 'extra' => 1]);
    log_debug('Quiet line');

    $data = toolbar_collect(toolbar_test_page());
    $html = toolbar_render($data);

    assert_true(count($data['queries']) >= 1);
    assert_contains('nobody@example.com', $html);
    assert_contains('Toolbar saw this line', $html);
    assert_contains('&quot;extra&quot;:1', $html);
    assert_contains('Quiet line', $html);
    assert_not_contains('class="cf-slow"', $html);

    // Every query counts as slow with a threshold this low.
    config_set('database.slow_query_ms', 0.0001);
    $html = toolbar_render(toolbar_collect(toolbar_test_page()));
    assert_contains('class="cf-slow"', $html);
    assert_contains('slow', $html);
    Database::flushQueryLog();
});

test('send_response() appends the toolbar to a rendered page when debugging', function () {
    if (!APP_DEBUG) {
        skip('APP_DEBUG is off');
    }

    http_use_app_routes();
    http_get('/');

    ob_start();
    send_response(view('home', ['php_version' => PHP_VERSION, 'framework_version' => '0', 'locales' => ['en']]));
    $output = (string) ob_get_clean();

    assert_contains('id="cf-toolbar"', $output);
    assert_contains('(home)', $output);
});
