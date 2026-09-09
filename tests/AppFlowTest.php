<?php

// End-to-end tests of the real routes, controllers and views on an
// in-memory SQLite database. Every test runs inside a transaction that is
// rolled back afterwards, so tests never see each other's rows. Add tests
// for your own routes here or in new files (make:crud writes one).

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
});

after_each(function () {
    if (Database::transactionLevel() > 0) {
        Database::rollBack();
    }
});

test('the starter page renders inside the layout', function () {
    http_get('/')->assertOk()
        ->assertSee(app_name())
        ->assertSee(__('messages.eyebrow'))
        ->assertSee('routes/web.php')
        ->assertSee('PHP ' . PHP_VERSION)
        ->assertSee('<html lang="en">');

    http_get('/nope')->assertNotFound()->assertSee('404');
    http_json('GET', '/nope')->assertNotFound()->assertJson(['message' => 'Not Found']);
    http_post('/')->assertStatus(405)->assertHeader('Allow');
});

test('?lang= switches the page language and is remembered', function () {
    lang_add('fr', 'messages', ['eyebrow' => 'Votre application fonctionne']);
    $dir = lang_path('fr');
    @mkdir($dir, 0777, true);

    try {
        add_global_middleware('locale');

        http_get('/?lang=fr')->assertOk()->assertSee('Votre application fonctionne')->assertSee('<html lang="fr">');
        http_get('/')->assertOk()->assertSee('Votre application fonctionne');
        http_get('/?lang=en')->assertOk()->assertSee(__('messages.eyebrow', [], 'en'));
    } finally {
        @rmdir($dir);
    }
});

test('the users migration, factory and auth helpers work together', function () {
    $user = User::factory()->create(['email' => 'ann@example.com']);

    assert_database_has('users', ['email' => 'ann@example.com']);
    assert_true($user->verifyPassword('password'));
    assert_not_null($user->created_at);
    assert_same($user->id, User::findByEmail('ann@example.com')?->id);

    assert_true(auth_attempt('ann@example.com', 'password'));
    assert_same($user->id, auth_id());
    assert_false(auth_attempt('ann@example.com', 'wrong'));
});

test('policies protect routes through the can middleware and route model binding', function () {
    gate_policy(User::class, TestUserPolicy::class);
    $ann = User::factory()->create();
    $bob = User::factory()->create();

    get('/login', fn () => 'login', 'login');
    get('/users/{id}/edit', fn (User $user) => 'editing ' . $user->name, null, ['auth', 'can:update,User@id']);

    http_get('/users/' . $ann->id . '/edit')->assertRedirect('/login');

    acting_as($ann);
    http_get('/users/' . $ann->id . '/edit')->assertOk()->assertSee('editing ' . $ann->name);
    http_get('/users/' . $bob->id . '/edit')->assertForbidden();
    http_get('/users/999/edit')->assertNotFound();

    assert_true($ann->can('update', $ann));
    assert_false($ann->can('update', $bob));
});

test('the throttle middleware limits repeated requests', function () {
    rate_limit_clear('203.0.113.1|POST|/attempt');
    post('/attempt', fn () => 'ok', 'attempt', ['throttle:3,1']);
    global_middleware([]);

    for ($i = 0; $i < 3; $i++) {
        http_post('/attempt')->assertOk();
    }

    http_post('/attempt')->assertStatus(429)->assertHeader('Retry-After');
    http_json('POST', '/attempt')->assertStatus(429);
});
