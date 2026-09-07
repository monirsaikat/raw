<?php

// End-to-end tests of the real routes, controllers and views on an
// in-memory SQLite database. Every test runs inside a transaction that is
// rolled back afterwards, so tests never see each other's rows.

class TestUserPolicy
{
    public function update(User $user, User $target): bool
    {
        return $user->id === $target->id;
    }
}

before_each(function () {
    use_test_database();
    http_use_app_routes();
    Database::beginTransaction();

    foreach (['/login', '/register', '/contact'] as $path) {
        rate_limit_clear('203.0.113.1|POST|' . $path);
    }
});

after_each(function () {
    if (Database::transactionLevel() > 0) {
        Database::rollBack();
    }
});

test('public pages render inside the layout', function () {
    http_get('/')->assertOk()->assertSee(app_name())->assertSee('href="/about"')->assertSee('Login');
    http_get('/about')->assertOk();
    http_get('/contact')->assertOk()->assertSee('name="_token"');
    http_get('/nope')->assertNotFound()->assertSee('404');
    http_json('GET', '/nope')->assertNotFound()->assertJson(['message' => 'Not Found']);
    http_json('GET', '/api/ping')->assertOk()->assertJson(['pong' => true])->assertJsonPath('user', null);
});

test('the contact form validates, stores the message and flashes a confirmation', function () {
    http_get('/contact');

    http_post('/contact', ['name' => '', 'email' => 'bad', 'message' => 'hi'])
        ->assertRedirect('/contact')
        ->assertSessionHasErrors(['name', 'email'])
        ->assertValid('message');

    http_get('/contact')->assertOk()->assertSee('The name field is required.')->assertSee('value="bad"');

    http_post('/contact', ['name' => 'Ann', 'email' => 'ann@example.com', 'message' => 'Hello there'])
        ->assertRedirect('/contact')
        ->assertSessionHasNoErrors();

    http_get('/contact')->assertSee('Thanks, Ann!');

    assert_database_has('messages', ['email' => 'ann@example.com', 'message' => 'Hello there']);
    assert_same(1, Message::count());
});

test('registration creates the account and logs the user in', function () {
    http_get('/register');

    http_post('/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
    ])->assertRedirect('/account')->assertAuthenticated();

    http_get('/account')->assertOk()->assertSee('Welcome, New User');
    http_get('/register')->assertRedirect('/');

    assert_database_has('users', ['email' => 'new@example.com']);
    assert_true(User::first()->verifyPassword('secret123'));

    acting_as(null);
    http_get('/register');
    http_post('/register', [
        'name' => 'Dup',
        'email' => 'new@example.com',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
    ])->assertRedirect('/register')->assertSessionHasErrors(['email' => 'An account with this email already exists.']);
});

test('login redirects to the intended page; wrong credentials show an error', function () {
    $user = User::factory()->create(['email' => 'ann@example.com']);

    http_get('/account')->assertRedirect('/login')->assertSessionHas('_intended');
    http_get('/login')->assertOk();

    http_post('/login', ['email' => 'ann@example.com', 'password' => 'wrong'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email')
        ->assertGuest();

    http_get('/login')->assertSee('Those credentials do not match our records.')->assertSee('value="ann@example.com"');

    http_post('/login', ['email' => 'ann@example.com', 'password' => 'password'])
        ->assertRedirect('/account')
        ->assertAuthenticated($user);

    http_get('/account')->assertOk()->assertSee($user->name)->assertSee('Log out');
    http_get('/login')->assertRedirect('/');

    http_post('/logout')->assertRedirect('/')->assertGuest();
    http_get('/account')->assertRedirect('/login');
});

test('the api reports the logged-in user without secrets', function () {
    $user = User::factory()->create();

    acting_as($user);

    http_json('GET', '/api/ping')->assertOk()
        ->assertJsonPath('user.name', $user->name)
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonMissing('user.password')
        ->assertJsonMissing('user.remember_token');
});

test('policies protect routes through the can middleware and route model binding', function () {
    gate_policy(User::class, TestUserPolicy::class);
    $ann = User::factory()->create();
    $bob = User::factory()->create();

    get('/users/{id}/edit', fn (User $user) => 'editing ' . $user->name, null, ['auth', 'can:update,User@id']);

    http_get('/users/' . $ann->id . '/edit')->assertRedirect('/login');

    acting_as($ann);
    http_get('/users/' . $ann->id . '/edit')->assertOk()->assertSee('editing ' . $ann->name);
    http_get('/users/' . $bob->id . '/edit')->assertForbidden();
    http_get('/users/999/edit')->assertNotFound();

    assert_true($ann->can('update', $ann));
    assert_false($ann->can('update', $bob));
});

test('repeated login attempts are throttled', function () {
    http_get('/login');

    for ($i = 0; $i < 5; $i++) {
        http_post('/login', ['email' => 'x@example.com', 'password' => 'bad'])->assertRedirect('/login');
    }

    http_post('/login', ['email' => 'x@example.com', 'password' => 'bad'])->assertStatus(429)->assertHeader('Retry-After');
    http_json('POST', '/login', ['email' => 'x@example.com', 'password' => 'bad'])->assertStatus(429);
});
