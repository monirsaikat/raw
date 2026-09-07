# YourApp framework

A small, dependency-free PHP 8.3 framework: procedural helpers where that reads
best, classes where state matters. Smarty 5 templates (vendored, no Composer),
PDO on MySQL, Bootstrap self-hosted. Everything runs on XAMPP or the built-in
PHP server.

## Quick start

```bash
cp .env.example .env            # then fill in DB_* and APP_NAME
php console.php key:generate    # signs remember-me tokens
php console.php migrate
php console.php serve           # http://127.0.0.1:8000
php console.php test            # runs tests/*.php
```

On Apache the app also works from a sub-directory (`/saikat/test1/`) with no
configuration; `.htaccess` routes everything through `index.php`.

## Layout

| Path | Purpose |
| --- | --- |
| `index.php`, `console.php` | Web and CLI entry points; both load `core/bootstrap.php` |
| `core/` | The framework. Function files (`route.php`, `http.php`, …) and classes (`Model`, `QueryBuilder`, `View`, …) |
| `config/` | Plain PHP arrays read with `config('app.name')`; values come from `.env` via `env()` |
| `routes/web.php` | Route definitions |
| `controllers/`, `models/`, `services/` | Autoloaded app classes |
| `middleware/` | App middleware, one file per handler, loaded automatically |
| `views/` | Smarty templates: `layouts/`, `includes/`, `errors/`, pages |
| `database/migrations/` | SQL files with `-- up` / `-- down` sections; `database/seeders/` |
| `storage/` | Logs, cache, compiled templates (git-ignored, web-blocked) |
| `tests/` | Test files run by `php console.php test` |

## Request lifecycle

`index.php` → `core/bootstrap.php` (env, config, error handlers, modules) →
routes → `route()`: security headers, match, global middleware (`csrf`) then
route middleware, controller action, response sent. Exceptions anywhere land in
`handle_exception()`, which logs 5xx errors and renders either the debug page
(`APP_DEBUG=true`), a JSON payload (`Accept: application/json`) or
`views/errors/error.tpl`.

## Routing

```php
get('/', 'HomeController@index', 'home');
post('/contact', 'ContactController@store', 'contact.store', ['throttle:10,1']);
get('/user/{id:\d+}', 'UserController@show', 'user');       // regex constraint
get('/docs/{page?}', 'DocsController@show', 'docs');         // optional segment
delete('/posts/{id}', 'PostController@destroy', 'posts.destroy');

group(['prefix' => '/admin', 'middleware' => ['auth'], 'name' => 'admin.'], function () {
    get('/users', 'AdminController@users', 'users');          // /admin/users, name admin.users
});
```

Actions: `'Controller@method'`, an invokable class name, `[class, method]`, or a
closure (not cacheable). Parameters arrive positionally. `any()` and
`route_map(['GET', 'POST'], …)` register several methods. HEAD falls back to
GET; a path that exists only for other methods gets a 405 with `Allow`.

URLs: `route_url('user', ['id' => 5, 'tab' => 'posts'])` → `/user/5?tab=posts`,
`url('about')`, `app_url()`. In templates: `{navigate name='user' id=$user.id}`,
`{url path='about'}`, `{asset path='assets/css/app.css'}` (adds `?v=mtime`).
`route_is('admin.*')` / `{if 'home'|route_is}` for active nav links.

Production: `php console.php route:cache` writes `bootstrap/cache/routes.php`,
which is used whenever `APP_DEBUG` is off. `route:list` prints the table.

## Controllers and responses

An action may return a string (HTML), an array or Model (sent as JSON), a
`Response`, or nothing.

```php
return view('contact');                        // views/contact.tpl
return redirect_route('account');
return back();                                 // same-host Referer, else last GET, else /
return json(['ok' => true], 201);
return response('text', 200, ['X-Thing' => 'y']);
abort(404);  abort_unless($post->author_id === auth_id(), 403);
```

Reading input (never `$_REQUEST`; cookies are excluded, JSON bodies merged):
`input('name')` (trimmed), `request('name')`, `query('page')`, `input_only([...])`,
`has_input('remember')`, `request_file('avatar')`, `old('email')`.
Request facts: `request_method()` (honours `_method` spoofing), `request_path()`,
`request_ip()`, `wants_json()`, `request_is_ajax()`, `request_header('X-Foo')`.

## Validation

```php
$data = validated(input(), [
    'name' => 'required|max:100',
    'email' => 'required|email|unique:users,email',
    'password' => 'required|min:8|confirmed',
], ['email.unique' => 'That email is taken.']);
```

`validated()` returns only the listed fields, so it is safe to pass to
`Model::create()`. On failure it throws a `ValidationException`; the handler
flashes `errors` and `old` and redirects back (or returns 422 JSON). Templates
receive `$errors` and `$old` automatically. `validate()` returns the error array
instead; `back_with_errors([...])` is the manual equivalent.

Rules: required, present, required_if/with/without, accepted, nullable,
sometimes, string, numeric, integer, boolean, array, email, url, ip, json, date,
date_format, before, after, alpha, alpha_num, alpha_dash, digits, regex,
not_regex, starts_with, ends_with, min, max, between, size, in, not_in,
confirmed, same, different, unique, exists. Add your own with
`validator_extend('even', fn ($v) => $v % 2 === 0, 'The :field must be even.')`.
Rules use array syntax when a pattern contains `|`: `['required', 'regex:/a|b/']`.

## Database

`Database::table('users')` returns a query builder; `Database::select($sql, $bindings)`
and friends run raw prepared statements. `Database::transaction(fn () => …)`.

```php
Database::table('posts')->where('status', 'published')->whereIn('id', $ids)
    ->orderByDesc('created_at')->paginate(20);
Database::table('users')->where('id', 3)->update(['name' => 'X']);
```

Identifiers are validated and quoted, operators whitelisted, values bound.
`Database::raw('NOW()')` marks a trusted fragment. Query log (debug mode only)
appears on the exception page.

### Models

```php
class Post extends Model
{
    protected static string $table = 'posts';            // default: snake_case plural of class
    protected static array $fillable = ['title', 'body'];
    protected static array $hidden = ['secret'];
    protected static bool $timestamps = true;              // created_at / updated_at
}

Post::find(1);  Post::findOrFail(1);  Post::all();
Post::where('published', 1)->latest()->get();          // static calls forward to the builder
$post = Post::create(['title' => 'Hi', 'body' => '…']);
$post->update(['title' => 'Hello']);  $post->title;  $post['title'];  $post->delete();
```

Models are `ArrayAccess` and `JsonSerializable`, so `{$user.name}` works in
templates and returning a model from an action sends JSON without `$hidden`.

### Migrations

`php console.php make:migration create_posts_table` creates a timestamped SQL
file with `-- up` and `-- down` sections. `migrate` runs pending files in a
batch, `migrate:rollback [steps]` reverts batches, `migrate:status` lists them.
`db:seed` runs every `database/seeders/*.php` (each returns a closure).

## Auth

`auth_attempt($email, $password, $remember)` verifies in constant time, rehashes
outdated hashes and logs in. `auth_user()` (Model or null), `auth_check()`,
`auth_id()`, `auth_login($user, $remember)`, `auth_logout()`,
`auth_intended($default)` after login. Middleware: `auth` (redirects to the
login route, remembers the intended URL, 401 for JSON) and `guest`.
Remember-me stores an HMAC of the cookie token in `users.remember_token`.
Settings: `config/auth.php`.

## Sessions, flash, cache, throttling

Sessions start lazily on first use (`session_get/set/has/pull/forget`), rotate
on login, and expire after `SESSION_LIFETIME` idle minutes. `flash('success',
'Saved')` shows on the next request via `views/includes/alerts.tpl`;
`flash_now()` for the current one. `cache_remember('key', 3600, fn () => …)`,
`cache_get/set/forget/flush`. `['throttle:5,1']` limits a route to 5 requests
per minute per IP and answers 429 with `Retry-After`.

## Middleware

```php
// middleware/admin.php (or: php console.php make:middleware admin)
middleware('admin', function (callable $next) {
    abort_unless(auth_user()?->is_admin, 403);
    return $next();
});
```

Handlers receive `$next` plus colon parameters (`'throttle:5,1'`). Attach per
route, per group, or with `add_global_middleware('admin')`.

## Views

`view('auth/login', [...])` renders `views/auth/login.tpl`. Every template gets
`$app_name`, `$auth_user`, `$errors`, `$old`, `$flash`, `$current_route`;
`View::share('key', $value)` adds more. Output is auto-escaped; `{$html|raw}`
opts out. Tags: `{csrf_field}`, `{csrf_meta}`, `{method_field method='DELETE'}`,
`{navigate}`, `{url}`, `{asset}`, `{current_year}`, `{csp_nonce}`. Inline
scripts need `<script nonce="{csp_nonce}">` because the Content-Security-Policy
allows only same-origin scripts (see `config/security.php`).

Custom error pages: add `views/errors/404.tpl`; `errors/error.tpl` is the
fallback for every status.

## Errors and logging

All PHP warnings and notices are thrown as `ErrorException` (the `@` operator
still silences), deprecations are logged. `log_info('User {id} logged in',
['id' => 5])` and `log_error/warning/debug` write to
`storage/logs/app-YYYY-MM-DD.log`; `LOG_LEVEL` sets the threshold. With
`APP_DEBUG=true` the exception page shows the code excerpt, chained causes and
the stack trace, deliberately without argument values.

## Console

```
serve [host:port]        route:list  route:cache  route:clear
migrate  migrate:rollback [steps]  migrate:status  db:seed
make:controller  make:model  make:middleware  make:migration  make:seeder
view:clear  cache:clear  key:generate  test [filter]
```

## Tests

`tests/*.php` files call `test('name', fn () => …)` with `assert_same`,
`assert_true`, `assert_contains`, `assert_throws`, and so on (see
`core/testing.php`). State is reset between tests; `test_next_request()`
simulates a request boundary so flash/session behaviour can be tested. The
suite needs no database.

## Production checklist

- `APP_DEBUG=false`, `APP_ENV=production`, a real `APP_KEY`.
- `php console.php route:cache` after deploying route changes; `view:clear`
  after template changes (compile checks are off in production).
- Enable OPcache (`opcache.enable=1`, `opcache.validate_timestamps=0` on
  immutable deploys).
- Serve over HTTPS and set `HSTS_ENABLED=true`; session cookies get the
  `Secure` flag automatically on HTTPS.
- `storage/` must be writable by the web server; everything else read-only.
