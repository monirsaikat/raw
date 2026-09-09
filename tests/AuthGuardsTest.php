<?php

// Multiple guards: a customer on 'web' and a staff member on 'admin' logged
// in at the same time, each with its own session key, login route, intended
// URL and remember-me cookie.

class GuardTestAdmin extends Model
{
    use Authorizable;

    protected static string $table = 'admins';
    protected static bool $timestamps = false;
    protected static array $fillable = ['email', 'password', 'remember_token'];
    protected static array $hidden = ['password', 'remember_token'];
}

class GuardTestAdminPolicy
{
    public function manage(GuardTestAdmin $admin, User $user): bool
    {
        return true;
    }
}

function guard_test_config(): void
{
    config_set('auth.default', 'web');
    config_set('auth.guards', [
        'web' => ['model' => 'User'],
        'admin' => [
            'model' => 'GuardTestAdmin',
            'login_route' => 'admin.login',
            'home_route' => 'admin.dashboard',
        ],
        'api' => ['model' => 'User', 'session_key' => 'api_user', 'remember' => ['cookie' => 'api_remember']],
    ]);
}

function guard_test_admin(string $email = 'root@example.com'): GuardTestAdmin
{
    return GuardTestAdmin::create(['email' => $email, 'password' => auth_hash('secret')]);
}

before_each(function () {
    use_test_database();
    Database::beginTransaction();

    if (!Schema::hasTable('admins')) {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('remember_token', 100)->nullable();
        });
    }

    guard_test_config();
});

after_each(function () {
    if (Database::transactionLevel() > 0) {
        Database::rollBack();
    }
});

test('guards resolve their own model, session key and cookie, falling back to the top-level config', function () {
    assert_same('web', auth_guard());
    assert_same(['web', 'admin', 'api'], auth_guards());
    assert_same('User', auth_model());
    assert_same('GuardTestAdmin', auth_model('admin'));
    assert_same('auth_id', auth_session_key());
    assert_same('auth_id_admin', auth_session_key('admin'));
    assert_same('api_user', auth_session_key('api'), 'explicit session_key wins');
    assert_same('remember_me', auth_cookie_name('web'));
    assert_same('remember_me_admin', auth_cookie_name('admin'));
    assert_same('api_remember', auth_cookie_name('api'));
    assert_same('email', auth_guard_config('admin', 'username', 'email'), 'top-level fallback');
    assert_same('admin.login', auth_guard_config('admin', 'login_route'));
    assert_same('login', auth_guard_config('web', 'login_route'));
    assert_same('_intended', auth_intended_key());
    assert_same('_intended_admin', auth_intended_key('admin'));

    assert_throws(fn () => auth_user('nope'), RuntimeException::class, 'not defined');
    assert_throws(fn () => guard('nope'), RuntimeException::class, 'not defined');
});

test('a user and an admin can be logged in at once and log out independently', function () {
    $user = User::factory()->create();
    $admin = guard_test_admin();

    auth_login($user);
    auth_login($admin, guard: 'admin');

    assert_same($user->id, auth_id());
    assert_same($admin->id, auth_id('admin'));
    assert_true(auth_check() && auth_check('admin'));
    assert_false(auth_check('api'));
    assert_true(auth_user('admin') instanceof GuardTestAdmin);
    assert_same($user->id, auth_user()->id);

    auth_logout('admin');
    assert_false(auth_check('admin'));
    assert_true(auth_check(), 'the web session survives an admin logout');
    assert_null(auth_user('admin'));

    auth_login($admin, guard: 'admin');
    auth_logout_all();
    assert_true(auth_guest() && auth_guest('admin'));
});

test('auth_attempt with a guard checks that guard\'s table', function () {
    User::factory()->create(['email' => 'ann@example.com']);
    guard_test_admin('root@example.com');

    assert_true(auth_attempt('root@example.com', 'secret', guard: 'admin'));
    assert_false(auth_attempt('root@example.com', 'secret'), 'admins are not users');
    assert_false(auth_attempt('ann@example.com', 'password', guard: 'admin'), 'users are not admins');
    assert_true(auth_attempt('ann@example.com', 'password'));
    assert_same('root@example.com', auth_user('admin')->email);
    assert_same('ann@example.com', auth_user()->email);

    assert_throws(fn () => auth_login(999, guard: 'admin'), RuntimeException::class, 'not found');
});

test('the guard() object mirrors the helpers', function () {
    $admin = guard_test_admin();
    $g = guard('admin');

    assert_same('admin', $g->name());
    assert_same('GuardTestAdmin', $g->model());
    assert_true($g->guest());
    assert_true($g->attempt('root@example.com', 'secret'));
    assert_true($g->check());
    assert_same($admin->id, $g->id());
    assert_same($admin->id, $g->user()->id);
    assert_same('admin.login', $g->config('login_route'));
    assert_true(guard()->guest(), 'default guard untouched');

    $g->logout();
    assert_true($g->guest());

    $g->login($admin->id);
    assert_same($admin->id, auth_id('admin'));
});

test('auth_use_guard and the guard middleware change the request default', function () {
    $admin = guard_test_admin();
    auth_login($admin, guard: 'admin');

    assert_null(auth_user());
    auth_use_guard('admin');
    assert_same('admin', auth_guard());
    assert_same($admin->id, auth_user()->id);
    assert_same('auth_id_admin', auth_session_key());
    auth_use_guard(null);
    assert_same('web', auth_guard());
    assert_throws(fn () => auth_use_guard('nope'), RuntimeException::class);

    guard('admin')->use();
    assert_same('admin', auth_guard());
    auth_reset();
    assert_same('web', auth_guard(), 'auth_reset() clears the request guard');

    get('/admin/whoami', fn () => auth_guard() . ':' . (auth_user()?->email ?? 'guest'), null, ['guard:admin']);
    get('/whoami', fn () => auth_guard() . ':' . (auth_user()?->email ?? 'guest'));

    acting_as($admin, 'admin');
    assert_same('admin:root@example.com', http_get('/admin/whoami')->body());
    assert_same('web:guest', http_get('/whoami')->body());
});

test('auth:guard and guest:guard middleware use the guard\'s routes and intended key', function () {
    get('/login', fn () => 'user login', 'login', ['guest']);
    get('/admin/login', fn () => 'admin login', 'admin.login', ['guest:admin']);
    get('/', fn () => 'home', 'home');
    get('/admin', fn () => 'dashboard', 'admin.dashboard', ['auth:admin']);
    get('/account', fn () => 'account', 'account', ['auth']);
    get('/api/me', fn () => 'me', null, ['auth:api']);

    http_get('/admin')->assertRedirect('/admin/login');
    assert_same('http://localhost/admin', session_get('_intended_admin'));
    assert_null(session_get('_intended'));
    http_get('/account')->assertRedirect('/login');
    http_json('GET', '/api/me')->assertStatus(401);

    $admin = guard_test_admin();
    acting_as($admin, 'admin');

    http_get('/admin')->assertOk()->assertAuthenticated($admin, 'admin')->assertGuest('web');
    http_get('/admin/login')->assertRedirect('/admin');
    http_get('/login')->assertOk('the web guest middleware ignores the admin session');
    http_get('/account')->assertRedirect('/login')->assertGuest();

    auth_intended(null, 'admin');
    assert_same('/admin', auth_intended(null, 'admin')->headers['Location'], 'falls back to the admin home route');
    assert_same('http://localhost/account', auth_intended()->headers['Location'], 'the web intended URL is separate');
    assert_same('/', auth_intended()->headers['Location']);

    acting_as(User::factory()->create());
    http_get('/account')->assertOk()->assertAuthenticated();
    http_get('/admin')->assertOk('both sessions coexist');
});

test('policies see the admin when the admin guard is the request default', function () {
    gate_policy(User::class, GuardTestAdminPolicy::class);
    $admin = guard_test_admin();
    $user = User::factory()->create();

    assert_false(can('manage', $user), 'guest');

    auth_login($admin, guard: 'admin');
    assert_false(can('manage', $user), 'the web guard is still a guest');

    auth_use_guard('admin');
    assert_true(can('manage', $user));
    assert_true(gate()->forUser($admin)->allows('manage', $user));
});

test('remember-me cookies are per guard', function () {
    // Loaded from the table so the models carry the remember_token column.
    $admin = guard_test_admin()->refresh();
    $user = User::factory()->create()->refresh();

    auth_login($admin, remember: true, guard: 'admin');
    auth_login($user, remember: true);

    $adminToken = $admin->refresh()->remember_token;
    $userToken = $user->refresh()->remember_token;
    assert_not_null($adminToken);
    assert_not_null($userToken);
    assert_true($adminToken !== $userToken);

    auth_logout('admin');
    assert_null($admin->refresh()->remember_token);
    assert_same($userToken, $user->refresh()->remember_token, 'the web token is untouched');
});

test('a single-guard config without a guards block keeps working', function () {
    config_set('auth.guards', []);
    config_set('auth.default', 'web');

    assert_same(['web'], auth_guards());
    assert_same('User', auth_model());
    assert_same('auth_id', auth_session_key());
    assert_throws(fn () => auth_user('admin'), RuntimeException::class);

    $user = User::factory()->create();
    auth_login($user);
    assert_same($user->id, auth_user()->id);
});
