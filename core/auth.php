<?php

// Session-based authentication with optional "remember me" cookies, driven
// by config/auth.php (model class, username column, cookie settings).
// auth_user() returns a Model, which is ArrayAccess, so $user['name'] and
// {$auth_user.name} both work.

function auth_model(): string
{
    return (string) config('auth.model', 'User');
}

function auth_session_key(): string
{
    return (string) config('auth.session_key', 'auth_id');
}

function auth_id()
{
    $id = session_get(auth_session_key());

    if ($id === null && PHP_SAPI !== 'cli') {
        $id = auth_login_from_cookie();
    }

    return $id;
}

function auth_check(): bool
{
    return auth_id() !== null;
}

function auth_guest(): bool
{
    return !auth_check();
}

// The logged-in user, loaded once per request.
function auth_user(): ?Model
{
    if (empty($GLOBALS['__auth_loaded'])) {
        $id = auth_id();
        $model = auth_model();

        $GLOBALS['__auth_user'] = $id === null ? null : $model::find($id);
        $GLOBALS['__auth_loaded'] = true;

        // A session pointing at a deleted user is just a guest.
        if ($id !== null && $GLOBALS['__auth_user'] === null) {
            session_forget(auth_session_key());
        }
    }

    return $GLOBALS['__auth_user'];
}

function auth_set_user(?Model $user): void
{
    $GLOBALS['__auth_user'] = $user;
    $GLOBALS['__auth_loaded'] = true;
}

function auth_reset(): void
{
    unset($GLOBALS['__auth_user'], $GLOBALS['__auth_loaded']);
}

function auth_login(Model|int|string $user, bool $remember = false): void
{
    if (!$user instanceof Model) {
        $model = auth_model();
        $user = $model::find($user);
    }

    if ($user === null) {
        throw new RuntimeException('Cannot log in: user not found.');
    }

    session_regenerate();
    session_set(auth_session_key(), $user->getKey());
    auth_set_user($user);

    if ($remember) {
        auth_remember($user);
    }
}

// Verifies credentials and logs the user in. Returns false on failure.
function auth_attempt(string $username, string $password, bool $remember = false): bool
{
    $model = auth_model();
    $user = $model::where((string) config('auth.username', 'email'), $username)->first();

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

    auth_login($user, $remember);

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

function auth_logout(): void
{
    $user = auth_user();

    if ($user !== null) {
        auth_forget_remember($user);
    }

    session_forget(auth_session_key());
    auth_set_user(null);
    session_regenerate();
}

// After login, continue to the page the 'auth' middleware bounced from.
function auth_intended(?string $default = null): Response
{
    $intended = session_pull('_intended');

    if (is_string($intended) && url_is_local($intended)) {
        return redirect($intended);
    }

    return redirect($default ?? route_url((string) config('auth.home_route', 'home')));
}

// ------------------------------------------------------------ remember me --
// Cookie holds "id|token"; the database stores HMAC(token). A leaked
// database cannot forge cookies, and logging out invalidates the cookie.

function auth_cookie_name(): string
{
    return (string) config('auth.remember.cookie', 'remember_me');
}

function auth_token_hash(string $token): string
{
    return hash_hmac('sha256', $token, (string) config('app.key', ''));
}

function auth_remember(Model $user): void
{
    if (!array_key_exists('remember_token', $user->getAttributes())) {
        log_warning('Remember-me skipped: the users table has no remember_token column.');

        return;
    }

    $token = bin2hex(random_bytes(32));

    $user->forceFill(['remember_token' => auth_token_hash($token)])->save();

    auth_set_cookie(
        $user->getKey() . '|' . $token,
        time() + (int) config('auth.remember.days', 30) * 86400
    );
}

function auth_forget_remember(Model $user): void
{
    if (($user->getAttributes()['remember_token'] ?? null) !== null) {
        $user->forceFill(['remember_token' => null])->save();
    }

    if (isset($_COOKIE[auth_cookie_name()])) {
        auth_set_cookie('', time() - 3600);
    }
}

function auth_login_from_cookie()
{
    static $tried = false;

    if ($tried) {
        return null;
    }

    $tried = true;
    $cookie = $_COOKIE[auth_cookie_name()] ?? null;

    if (!is_string($cookie) || !str_contains($cookie, '|')) {
        return null;
    }

    [$id, $token] = explode('|', $cookie, 2);
    $model = auth_model();
    $user = $id !== '' ? $model::find($id) : null;
    $stored = $user['remember_token'] ?? null;

    if ($user === null || !is_string($stored) || !hash_equals($stored, auth_token_hash($token))) {
        auth_set_cookie('', time() - 3600);

        return null;
    }

    session_regenerate();
    session_set(auth_session_key(), $user->getKey());
    auth_set_user($user);

    return $user->getKey();
}

function auth_set_cookie(string $value, int $expires): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    setcookie(auth_cookie_name(), $value, [
        'expires' => $expires,
        'path' => (string) config('session.path', '/'),
        'domain' => (string) config('session.domain', ''),
        'secure' => (bool) config('session.secure', false) || request_is_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ------------------------------------------------------------- middleware --

middleware('auth', function (callable $next) {
    if (auth_check()) {
        return $next();
    }

    if (wants_json()) {
        abort(401, 'Unauthenticated.');
    }

    if (request_method() === 'GET') {
        session_set('_intended', request_url());
    }

    // A named login route when the app defines one, else the login_path.
    $login = (string) config('auth.login_route', 'login');

    return isset(route_names()[$login])
        ? redirect_route($login)
        : redirect(url((string) config('auth.login_path', '/login')));
});

middleware('guest', function (callable $next) {
    if (!auth_check()) {
        return $next();
    }

    return redirect_route((string) config('auth.home_route', 'home'));
});
