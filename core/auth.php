<?php

// Session-based authentication with optional "remember me" cookies, driven
// by config/auth.php. Several guards can be logged in at once (a customer
// on the 'web' guard and a staff member on 'admin'); every helper takes an
// optional trailing $guard and uses the default guard otherwise:
//
//   auth_user()            auth_user('admin')          guard('admin')->user()
//   auth_attempt($e, $p)   auth_attempt($e, $p, guard: 'admin')
//   ['auth']               ['auth:admin']              ['guest:admin']
//
// auth_user() returns a Model, which is ArrayAccess, so $user['name'] and
// {$auth_user.name} both work.

// ------------------------------------------------------------------ guards --

// The default guard: set for the request with auth_use_guard() (or the
// 'guard:name' middleware), else config('auth.default').
function auth_guard(): string
{
    return $GLOBALS['__auth_guard'] ?? (string) config('auth.default', 'web');
}

function auth_use_guard(?string $guard): void
{
    if ($guard === null) {
        unset($GLOBALS['__auth_guard']);

        return;
    }

    auth_guard_config($guard);
    $GLOBALS['__auth_guard'] = $guard;
}

function auth_guards(): array
{
    $guards = (array) config('auth.guards', []);

    return $guards === [] ? ['web'] : array_keys($guards);
}

// One guard's settings. Keys missing from the guard entry fall back to the
// top-level config/auth.php values, so a single-guard app needs no 'guards'
// block at all.
function auth_guard_config(?string $guard = null, ?string $key = null, mixed $default = null): mixed
{
    $guard ??= auth_guard();
    $guards = (array) config('auth.guards', []);

    if (!isset($guards[$guard]) && !($guards === [] && $guard === 'web')) {
        throw new RuntimeException("Auth guard [$guard] is not defined in config/auth.php.");
    }

    $settings = (array) ($guards[$guard] ?? []);

    if ($key === null) {
        return $settings;
    }

    $value = $settings;

    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            $value = null;
            break;
        }

        $value = $value[$segment];
    }

    return $value ?? config('auth.' . $key, $default);
}

function auth_model(?string $guard = null): string
{
    return (string) auth_guard_config($guard, 'model', 'User');
}

// 'auth_id' for the default guard, 'auth_id_<guard>' for the others, unless
// the guard sets its own session_key.
function auth_session_key(?string $guard = null): string
{
    $guard ??= auth_guard();
    $explicit = ((array) config('auth.guards', []))[$guard]['session_key'] ?? null;

    if (is_string($explicit) && $explicit !== '') {
        return $explicit;
    }

    $base = (string) config('auth.session_key', 'auth_id');

    return $guard === (string) config('auth.default', 'web') ? $base : $base . '_' . $guard;
}

function auth_id(?string $guard = null)
{
    $guard ??= auth_guard();
    $id = session_get(auth_session_key($guard));

    if ($id === null && PHP_SAPI !== 'cli') {
        $id = auth_login_from_cookie($guard);
    }

    return $id;
}

function auth_check(?string $guard = null): bool
{
    return auth_id($guard) !== null;
}

function auth_guest(?string $guard = null): bool
{
    return !auth_check($guard);
}

// The logged-in user of a guard, loaded once per request.
function auth_user(?string $guard = null): ?Model
{
    $guard ??= auth_guard();

    if (!array_key_exists($guard, $GLOBALS['__auth_users'] ?? [])) {
        $id = auth_id($guard);
        $model = auth_model($guard);

        $GLOBALS['__auth_users'][$guard] = $id === null ? null : $model::find($id);

        // A session pointing at a deleted user is just a guest.
        if ($id !== null && $GLOBALS['__auth_users'][$guard] === null) {
            session_forget(auth_session_key($guard));
        }
    }

    return $GLOBALS['__auth_users'][$guard];
}

function auth_set_user(?Model $user, ?string $guard = null): void
{
    $GLOBALS['__auth_users'][$guard ?? auth_guard()] = $user;
}

// Forget loaded users, the request guard and cookie attempts (new request).
function auth_reset(): void
{
    unset($GLOBALS['__auth_users'], $GLOBALS['__auth_guard'], $GLOBALS['__auth_cookie_tried']);
}

function auth_login(Model|int|string $user, bool $remember = false, ?string $guard = null): void
{
    $guard ??= auth_guard();

    if (!$user instanceof Model) {
        $model = auth_model($guard);
        $user = $model::find($user);
    }

    if ($user === null) {
        throw new RuntimeException('Cannot log in: user not found.');
    }

    session_regenerate();
    session_set(auth_session_key($guard), $user->getKey());
    auth_set_user($user, $guard);

    if ($remember) {
        auth_remember($user, $guard);
    }
}

// Verifies credentials and logs the user in. Returns false on failure.
function auth_attempt(string $username, string $password, bool $remember = false, ?string $guard = null): bool
{
    $guard ??= auth_guard();
    $model = auth_model($guard);
    $user = $model::where((string) auth_guard_config($guard, 'username', 'email'), $username)->first();

    // Verify against a dummy hash when the user is unknown so a wrong email
    // and a wrong password take the same time (no account enumeration).
    $hash = $user['password'] ?? auth_dummy_hash();

    if (!auth_verify($password, $hash) || $user === null) {
        return false;
    }

    // Transparent upgrade when PHP's default algorithm or cost changes.
    if (auth_needs_rehash($hash) && array_key_exists('password', $user->getAttributes())) {
        $user->forceFill(['password' => auth_hash($password)])->save();
    }

    auth_login($user, $remember, $guard);

    return true;
}

// ------------------------------------------------------------- passwords --
// Wrappers around password_hash()/password_verify() so the algorithm and
// options live in one place (config('auth.password_options')).

function auth_hash(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT, (array) config('auth.password_options', []));
}

function auth_verify(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}

function auth_needs_rehash(string $hash): bool
{
    return password_needs_rehash($hash, PASSWORD_DEFAULT, (array) config('auth.password_options', []));
}

function auth_dummy_hash(): string
{
    static $hash = null;

    return $hash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
}

function auth_logout(?string $guard = null): void
{
    $guard ??= auth_guard();
    $user = auth_user($guard);

    if ($user !== null) {
        auth_forget_remember($user, $guard);
    }

    session_forget(auth_session_key($guard));
    auth_set_user(null, $guard);
    session_regenerate();
}

// Log out of every guard at once.
function auth_logout_all(): void
{
    foreach (auth_guards() as $guard) {
        auth_logout($guard);
    }
}

// After login, continue to the page the 'auth' middleware bounced from.
function auth_intended(?string $default = null, ?string $guard = null): Response
{
    $intended = session_pull(auth_intended_key($guard));

    if (is_string($intended) && url_is_local($intended)) {
        return redirect($intended);
    }

    return redirect($default ?? route_url((string) auth_guard_config($guard, 'home_route', 'home')));
}

function auth_intended_key(?string $guard = null): string
{
    $guard ??= auth_guard();

    return $guard === (string) config('auth.default', 'web') ? '_intended' : '_intended_' . $guard;
}

// ------------------------------------------------------------ remember me --
// Cookie holds "id|token"; the database stores HMAC(token). A leaked
// database cannot forge cookies, and logging out invalidates the cookie.

function auth_cookie_name(?string $guard = null): string
{
    $guard ??= auth_guard();
    $explicit = ((array) config('auth.guards', []))[$guard]['remember']['cookie'] ?? null;

    if (is_string($explicit) && $explicit !== '') {
        return $explicit;
    }

    $base = (string) config('auth.remember.cookie', 'remember_me');

    return $guard === (string) config('auth.default', 'web') ? $base : $base . '_' . $guard;
}

function auth_token_hash(string $token): string
{
    return hash_hmac('sha256', $token, (string) config('app.key', ''));
}

function auth_remember(Model $user, ?string $guard = null): void
{
    if (!array_key_exists('remember_token', $user->getAttributes())) {
        log_warning('Remember-me skipped: the table has no remember_token column.');

        return;
    }

    $token = bin2hex(random_bytes(32));

    $user->forceFill(['remember_token' => auth_token_hash($token)])->save();

    auth_set_cookie(
        $user->getKey() . '|' . $token,
        time() + (int) auth_guard_config($guard, 'remember.days', 30) * 86400,
        $guard
    );
}

function auth_forget_remember(Model $user, ?string $guard = null): void
{
    if (($user->getAttributes()['remember_token'] ?? null) !== null) {
        $user->forceFill(['remember_token' => null])->save();
    }

    if (isset($_COOKIE[auth_cookie_name($guard)])) {
        auth_set_cookie('', time() - 3600, $guard);
    }
}

function auth_login_from_cookie(?string $guard = null)
{
    $guard ??= auth_guard();

    if (!empty($GLOBALS['__auth_cookie_tried'][$guard])) {
        return null;
    }

    $GLOBALS['__auth_cookie_tried'][$guard] = true;
    $cookie = $_COOKIE[auth_cookie_name($guard)] ?? null;

    if (!is_string($cookie) || !str_contains($cookie, '|')) {
        return null;
    }

    [$id, $token] = explode('|', $cookie, 2);
    $model = auth_model($guard);
    $user = $id !== '' ? $model::find($id) : null;
    $stored = $user['remember_token'] ?? null;

    if ($user === null || !is_string($stored) || !hash_equals($stored, auth_token_hash($token))) {
        auth_set_cookie('', time() - 3600, $guard);

        return null;
    }

    session_regenerate();
    session_set(auth_session_key($guard), $user->getKey());
    auth_set_user($user, $guard);

    return $user->getKey();
}

function auth_set_cookie(string $value, int $expires, ?string $guard = null): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    setcookie(auth_cookie_name($guard), $value, [
        'expires' => $expires,
        'path' => (string) config('session.path', '/'),
        'domain' => (string) config('session.domain', ''),
        'secure' => (bool) config('session.secure', false) || request_is_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ---------------------------------------------------------- guard object --

// guard('admin')->user(), ->check(), ->attempt(...): the same helpers as
// methods, for code that prefers passing a guard around.
function guard(?string $name = null): Guard
{
    return new Guard($name ?? auth_guard());
}

// ------------------------------------------------------------- middleware --

// 'auth' or 'auth:admin' — a logged-in user on that guard, else redirect to
// the guard's login route (401 for JSON clients).
middleware('auth', function (callable $next, ?string $guard = null) {
    $guard ??= auth_guard();

    if (auth_check($guard)) {
        return $next();
    }

    if (wants_json()) {
        abort(401, 'Unauthenticated.');
    }

    if (request_method() === 'GET') {
        session_set(auth_intended_key($guard), request_url());
    }

    // A named login route when the app defines one, else the login_path.
    $login = (string) auth_guard_config($guard, 'login_route', 'login');

    return isset(route_names()[$login])
        ? redirect_route($login)
        : redirect(url((string) auth_guard_config($guard, 'login_path', '/login')));
});

// 'guest' or 'guest:admin' — only for visitors not logged in on that guard.
middleware('guest', function (callable $next, ?string $guard = null) {
    $guard ??= auth_guard();

    if (!auth_check($guard)) {
        return $next();
    }

    return redirect_route((string) auth_guard_config($guard, 'home_route', 'home'));
});

// 'guard:admin' — make a guard the default for the rest of the request, so
// auth_user(), can(), policies and {$auth_user} all refer to it. Put it
// first in an admin route group: ['guard:admin', 'auth'].
middleware('guard', function (callable $next, string $guard) {
    auth_use_guard($guard);

    return $next();
});
