<?php

test('templates auto-escape output unless |raw is used', function () {
    $tpl = View::instance()->smarty()->createTemplate('string:{$x} {$x|raw}');
    $tpl->assign('x', '<b>');

    assert_same('&lt;b&gt; <b>', $tpl->fetch());
});

test('view() renders app templates with the standard globals', function () {
    $html = view('errors/error', ['status' => 404, 'title' => 'Not Found', 'message' => 'No <such> page']);

    assert_contains('<h1>404</h1>', $html);
    assert_contains('No &lt;such&gt; page', $html);
    assert_true(view_exists('errors/error'));
    assert_true(view_exists('views/errors/error'), 'legacy views/ prefix still accepted');
    assert_true(view_exists('errors/error.tpl'));
    assert_false(view_exists('nope/nope'));
});

test('template plugins: navigate, url, asset, csrf_field, route_is', function () {
    get('/user/{id}', fn () => '', 'user');
    get('/', fn () => '', 'home');
    dispatch('GET', '/');

    $tpl = View::instance()->smarty()->createTemplate(
        'string:{navigate name="user" id=5}|{url path="x"}|{if "home"|route_is}active{/if}|{if "nope"|route_is}wrong{/if}|{csrf_field}|{asset path="assets/css/app.css"}'
    );
    $out = $tpl->fetch();

    assert_contains('/user/5|/x|active||', $out);
    assert_contains('name="_token"', $out);
    assert_matches('#/assets/css/app\.css\?v=\d+#', $out);
});

test('data passed to one render does not leak into the next', function () {
    $view = View::instance();

    $first = $view->render('errors/error', ['status' => 500, 'title' => 'T', 'message' => 'Leak me']);
    $second = $view->render('errors/error', ['status' => 404, 'title' => 'T', 'message' => 'Other']);

    assert_contains('Leak me', $first);
    assert_not_contains('Leak me', $second);
});

test('flash and old input reach templates automatically', function () {
    flash('success', 'Saved!');
    flash('old', ['email' => 'a@b.c']);
    flash('errors', ['email' => ['Bad email']]);
    test_next_request();

    $tpl = View::instance()->smarty()->createTemplate('string:{include file="includes/alerts.tpl"}|{$old.email}|{$errors.email.0}|{$old.missing|default:"-"}');
    $tpl->assign(['flash' => flash_all(), 'old' => flash('old'), 'errors' => flash('errors')]);
    $out = $tpl->fetch();

    assert_contains('alert-success', $out);
    assert_contains('Saved!', $out);
    assert_contains('|a@b.c|Bad email|-', $out);
});

test('View::share makes a value available to every render', function () {
    View::share('shared_thing', 'yes');

    $tpl = View::instance()->render('errors/error', ['status' => 200, 'title' => 'x', 'message' => 'y']);
    assert_contains('<h1>200</h1>', $tpl);

    $string = View::instance()->smarty()->createTemplate('string:{$shared_thing}');
    $string->assign('shared_thing', 'yes');
    assert_same('yes', $string->fetch());
});
