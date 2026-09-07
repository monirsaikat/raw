<?php

class HcGreeter
{
    public function greet(string $name): string
    {
        return "Hello, $name!";
    }
}

class HcController
{
    public function __construct(private HcGreeter $greeter)
    {
    }

    public function hello(string $name): string
    {
        return $this->greeter->greet($name);
    }
}

class HcUser extends Model
{
    protected static array $guarded = [];
}

test('GET requests return the body with status and headers', function () {
    get('/hello/{name}', 'HcController@hello');
    get('/json', fn () => ['ok' => true, 'items' => [1, 2]]);
    get('/custom', fn () => response('made', 201, ['X-Made' => 'yes']));

    http_get('/hello/Ann')->assertOk()->assertSee('Hello, Ann!')->assertDontSee('Bob')->assertSeeText('Hello, Ann!');
    http_get('/json')->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=utf-8')
        ->assertJson(['ok' => true])
        ->assertJsonPath('items.1', 2)
        ->assertJsonCount(2, 'items')
        ->assertJsonMissing('nope')
        ->assertExactJson(['ok' => true, 'items' => [1, 2]]);
    http_get('/custom')->assertCreated()->assertHeader('X-Made', 'yes')->assertHeaderMissing('X-Other');
    http_get('/missing')->assertNotFound();
});

test('query strings, headers, JSON bodies and method spoofing reach the action', function () {
    get('/search', fn () => query('q') . '|' . request_header('X-Custom', 'none'));
    post('/items', fn () => request_method() . ':' . input('name'));
    put('/items/{id}', fn ($id) => request_method() . ":$id:" . input('name'));
    delete('/items/{id}', fn ($id) => request_method() . ":$id");
    post('/api/items', fn () => ['got' => request('name'), 'json' => request_is_json()]);

    http_get('/search?q=php', ['X-Custom' => 'c'])->assertSee('php|c');
    http_post('/items', ['name' => ' Box '])->assertSee('POST:Box');
    http_put('/items/4', ['name' => 'Crate'])->assertSee('PUT:4:Crate');
    http_delete('/items/4')->assertSee('DELETE:4');
    http_json('POST', '/api/items', ['name' => 'Jar'])->assertOk()->assertJson(['got' => 'Jar', 'json' => true]);
});

test('CSRF tokens are added automatically and a wrong token gives 419', function () {
    post('/save', fn () => 'saved');

    http_post('/save')->assertOk()->assertSee('saved');
    http_post('/save', ['_token' => 'wrong'])->assertStatus(419);
    http_json('POST', '/save')->assertOk();
});

test('the session persists across requests and flash data ages', function () {
    get('/set', function () {
        session_set('counter', 1);
        flash('note', 'hi');

        return 'set';
    });
    get('/read', fn () => session_get('counter') . ':' . (flash('note') ?? '-'));

    http_get('/set')->assertOk()->assertSessionHas('counter', 1)->assertSessionMissing('nope');
    http_get('/read')->assertSee('1:hi');
    http_get('/read')->assertSee('1:-');
});

test('validation failures redirect back with errors and old input', function () {
    get('/form', fn () => 'form');
    post('/form', function () {
        validated(input(), ['email' => 'required|email']);

        return 'ok';
    });

    http_get('/form');
    http_post('/form', ['email' => 'nope'])
        ->assertRedirect('/form')
        ->assertSessionHasErrors(['email'])
        ->assertSessionHasErrors(['email' => 'The email must be a valid email address.'])
        ->assertInvalid('email');

    http_get('/form');
    assert_same('nope', old('email'));
    assert_key_exists('email', flash('errors'));

    http_post('/form', ['email' => 'a@b.co'])->assertOk()->assertSessionHasNoErrors()->assertValid();
    http_json('POST', '/form', ['email' => 'x'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'The email must be a valid email address.');
});

test('redirects can be asserted and followed', function () {
    get('/', fn () => 'home', 'home');
    get('/go', fn () => redirect_route('home'));
    get('/away', fn () => redirect('http://localhost/?x=1'));

    http_get('/go')->assertRedirect('/')->assertRedirectToRoute('home')->assertRedirect();
    http_follow(http_get('/go'))->assertOk()->assertSee('home');
    http_get('/away')->assertRedirect('/?x=1');
    http_get('/')->assertNoRedirect();
});

test('acting_as authenticates requests and assertions read the auth state', function () {
    $user = HcUser::hydrate(['id' => 42, 'name' => 'Ann']);

    get('/me', fn () => auth_check() ? 'user ' . auth_id() : 'guest');
    get('/secret', fn () => 'secret', null, ['auth']);
    get('/login', fn () => 'login', 'login');
    get('/', fn () => '', 'home');

    http_get('/me')->assertSee('guest')->assertGuest();
    http_get('/secret')->assertRedirect('/login');

    acting_as($user);
    http_get('/me')->assertSee('user 42')->assertAuthenticated($user);
    http_get('/secret')->assertOk();

    acting_as(null);
    http_get('/me')->assertSee('guest')->assertGuest();
});

test('exceptions become error responses and stay inspectable', function () {
    get('/boom', function () {
        throw new RuntimeException('kaboom');
    });
    get('/gone', fn () => abort(410, 'Gone away'));

    $response = http_get('/boom');
    $response->assertServerError();
    assert_instance_of(RuntimeException::class, $response->exception);
    assert_same('kaboom', $response->exception->getMessage());

    http_get('/gone')->assertStatus(410)->assertSee('Gone away');
    http_json('GET', '/gone')->assertStatus(410)->assertJson(['message' => 'Gone away']);
});

test('route model binding loads records and answers 404 when missing', function () {
    use_test_database();
    $user = User::factory()->create(['name' => 'Bound One']);

    get('/users/{user}', fn (User $user) => 'name=' . $user->name);
    get('/users/{user}/posts/{page?}', fn (User $user, int $page = 1) => $user->name . '/' . $page);

    http_get('/users/' . $user->id)->assertOk()->assertSee('name=Bound One');
    http_get('/users/' . $user->id . '/posts')->assertSee('Bound One/1');
    http_get('/users/' . $user->id . '/posts/3')->assertSee('Bound One/3');
    http_get('/users/999')->assertNotFound();

    assert_database_has('users', ['name' => 'Bound One']);
    assert_database_missing('users', ['name' => 'Nobody']);
    assert_database_count('users', 1);

    Database::table('users')->delete();
});
