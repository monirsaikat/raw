<?php

class RouterTestController
{
    public function show($id, $tab = null)
    {
        return "user:$id:" . ($tab ?? 'none');
    }

    public function __invoke()
    {
        return 'invoked';
    }
}

test('static routes match exactly', function () {
    get('/about', fn () => 'about page', 'about');

    assert_same('about page', route_dispatch('GET', '/about'));

    $e = assert_throws(fn () => route_dispatch('GET', '/about/extra'), HttpException::class);
    assert_same(404, $e->status);
});

test('dynamic routes pass parameters positionally, optional ones default to null', function () {
    get('/user/{id}/{tab?}', 'RouterTestController@show', 'user');

    assert_same('user:5:none', route_dispatch('GET', '/user/5'));
    assert_same('user:5:posts', route_dispatch('GET', '/user/5/posts'));
});

test('parameter constraints are enforced', function () {
    get('/user/{id:\d+}', fn ($id) => "id=$id");

    assert_same('id=42', route_dispatch('GET', '/user/42'));

    $e = assert_throws(fn () => route_dispatch('GET', '/user/abc'), HttpException::class);
    assert_same(404, $e->status);
});

test('parameters are url-decoded and literal segments are regex-safe', function () {
    get('/tag/{name}', fn ($name) => $name);
    get('/a.b/{x}', fn ($x) => "dot:$x");

    assert_same('hello world', route_dispatch('GET', '/tag/hello%20world'));
    assert_same('dot:1', route_dispatch('GET', '/a.b/1'));
    assert_throws(fn () => route_dispatch('GET', '/aXb/1'), HttpException::class);
});

test('HEAD requests fall back to GET routes', function () {
    get('/page', fn () => 'body');

    assert_same('body', route_dispatch('HEAD', '/page'));
});

test('a path that exists for other methods answers 405 with Allow', function () {
    get('/thing', fn () => 'get');
    post('/thing', fn () => 'post');

    $e = assert_throws(fn () => route_dispatch('DELETE', '/thing'), HttpException::class);

    assert_same(405, $e->status);
    assert_same('GET, POST, HEAD', $e->headers['Allow']);
});

test('groups apply prefix, middleware and name prefix, and nest', function () {
    middleware('mark', fn (callable $next) => '[' . $next() . ']');

    group(['prefix' => '/admin', 'middleware' => ['mark'], 'name' => 'admin.'], function () {
        get('/users', fn () => 'users', 'users');

        group(['prefix' => 'reports'], function () {
            get('/daily', fn () => 'daily', 'daily');
        });
    });

    get('/outside', fn () => 'plain', 'outside');

    assert_same('[users]', route_dispatch('GET', '/admin/users'));
    assert_same('[daily]', route_dispatch('GET', '/admin/reports/daily'));
    assert_same('plain', route_dispatch('GET', '/outside'));
    assert_same('/admin/users', route_url('admin.users'));
    assert_same('/admin/reports/daily', route_url('admin.daily'));
});

test('route_url fills parameters and appends leftovers as a query string', function () {
    get('/user/{id}/{tab?}', fn () => '', 'user');
    get('/', fn () => '', 'home');

    assert_same('/', route_url('home'));
    assert_same('/user/7', route_url('user', ['id' => 7]));
    assert_same('/user/7/posts', route_url('user', ['id' => 7, 'tab' => 'posts']));
    assert_same('/user/7?page=2', route_url('user', ['id' => 7, 'page' => 2]));
    assert_same('/user/a%20b', route_url('user', ['id' => 'a b']));

    assert_throws(fn () => route_url('user'), RuntimeException::class, 'Missing parameter');
    assert_throws(fn () => route_url('nope'), RuntimeException::class, 'not defined');
});

test('route_url honours a configured base path', function () {
    get('/about', fn () => '', 'about');
    config_set('app.base_path', '/saikat/test1');

    assert_same('/saikat/test1/about', route_url('about'));
    assert_same('/saikat/test1/x', url('x'));
});

test('navigate escapes its output for HTML attributes', function () {
    get('/search', fn () => '', 'search');

    assert_same('/search?q=a&amp;b=c', navigate(['name' => 'search', 'q' => 'a', 'b' => 'c']));
});

test('invokable controllers and [class, method] actions work', function () {
    get('/invoke', 'RouterTestController');
    get('/pair/{id}', ['RouterTestController', 'show']);

    assert_same('invoked', route_dispatch('GET', '/invoke'));
    assert_same('user:1:none', route_dispatch('GET', '/pair/1'));
});

test('missing controllers and methods fail loudly', function () {
    get('/a', 'NoSuchController@index');
    get('/b', 'RouterTestController@nope');

    assert_throws(fn () => route_dispatch('GET', '/a'), RuntimeException::class, 'not found');
    assert_throws(fn () => route_dispatch('GET', '/b'), RuntimeException::class, 'not found');
});

test('route_is matches the current route name, with wildcards', function () {
    get('/admin/x', fn () => 'x', 'admin.x');

    assert_false(route_is('admin.x'), 'nothing dispatched yet');

    route_dispatch('GET', '/admin/x');

    assert_same('admin.x', current_route_name());
    assert_true(route_is('admin.x'));
    assert_true(route_is('admin.*'));
    assert_true(route_is('home', 'admin.*'));
    assert_false(route_is('home'));
});

test('route definitions survive a cache round-trip', function () {
    get('/user/{id:\d+}', 'RouterTestController@show', 'user', ['csrf']);

    $payload = eval('return ' . var_export(['routes' => routes_all(), 'names' => route_names()], true) . ';');

    routes_reset();
    routes_load($payload);

    assert_same('user:3:none', route_dispatch('GET', '/user/3'));
    assert_same('/user/3', route_url('user', ['id' => 3]));
});
