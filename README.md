# ComfreePHP

ComfreePHP is a small, dependency-free PHP 8.3 framework: procedural helpers where that reads
best, classes where state matters. Smarty 5 templates (vendored, no Composer),
PDO on MySQL/MariaDB or SQLite, Bootstrap self-hosted. Everything runs on XAMPP
or the built-in PHP server.

## Quick start

```bash
cp .env.example .env            # then fill in DB_* and APP_NAME
php console.php key:generate    # signs remember-me tokens
php console.php migrate
php console.php serve           # http://127.0.0.1:8000
php console.php test            # runs tests/*.php (database tests use in-memory SQLite)
```

On Apache the app also works from a sub-directory (`/saikat/test1/`) with no
configuration; `.htaccess` routes everything through `index.php`.

## Layout

| Path | Purpose |
| --- | --- |
| `index.php`, `console.php` | Web and CLI entry points; both load `core/bootstrap.php` |
| `core/` | The framework. Function files (`route.php`, `http.php`, …) and classes (`Model`, `QueryBuilder`, `Schema`, …) |
| `config/` | Plain PHP arrays read with `config('app.name')`; values come from `.env` via `env()` |
| `routes/web.php` | Route definitions |
| `controllers/`, `models/`, `services/` | Autoloaded app classes |
| `middleware/` | App middleware, one file per handler, loaded automatically |
| `policies/` | Authorization: `<Model>Policy` classes and `gates.php` |
| `views/` | Smarty templates: `layouts/`, `includes/`, `errors/`, pages |
| `database/migrations/` | PHP (schema builder) or SQL migrations; `seeders/`, `factories/` |
| `storage/` | Logs, cache, compiled templates, SQLite files (git-ignored, web-blocked) |
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
closure (not cacheable). Controllers are built by the container, so
constructor and method parameters that are type-hinted with a class are
injected; route parameters fill the remaining parameters in order. A
parameter typed with a model receives the record (route model binding):

```php
get('/posts/{id}', 'PostController@show');
public function show(PostRepository $repository, Post $post) { ... }   // Post::findOrFail($id), 404 if missing
```

`any()` and `route_map(['GET', 'POST'], …)` register several methods. HEAD
falls back to GET; a path that exists only for other methods gets a 405 with
`Allow`. `route_parameter('id')` reads a matched parameter anywhere.

URLs: `route_url('user', ['id' => 5, 'tab' => 'posts'])` → `/user/5?tab=posts`,
`url('about')`, `app_url()`. In templates: `{navigate name='user' id=$user.id}`,
`{url path='about'}`, `{asset path='assets/css/app.css'}` (adds `?v=mtime`).
`route_is('admin.*')` / `{if 'home'|route_is}` for active nav links.

Production: `php console.php route:cache` writes `bootstrap/cache/routes.php`,
which is used whenever `APP_DEBUG` is off. `route:list` prints the table.

## Controllers and responses

An action may return a string (HTML), an array, Model, Collection or Paginator
(sent as JSON), a `Response`, or nothing.

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

### Connections

`config/database.php` names connections (`mysql`, `sqlite`, `testing`) and the
default (`DB_CONNECTION`). `Database::connection('sqlite')` returns a
`Connection`; the static helpers use the default one.

```php
Database::select('SELECT * FROM users WHERE id = ?', [$id]);   // raw, bound
Database::transaction(function () { ... }, attempts: 3);        // retries deadlocks
Database::transaction(fn () => Database::transaction(fn () => ...)); // nested → savepoints
Database::listen(fn ($sql, $bindings, $ms) => ...);              // every query
Database::connection()->cursor($sql, $bindings);                 // streamed rows
```

Bindings are typed (ints as ints, DateTime as strings, bools as 0/1). A failed
statement throws `QueryException` with the SQL and bindings in its message.
`DB_SLOW_QUERY_MS` logs slow queries; `APP_DEBUG` keeps a query log that the
exception page shows.

### Query builder

`Database::table('users')` (or `Model::query()`) returns a `QueryBuilder`.
Identifiers are validated and quoted, operators whitelisted, values bound —
request data may be a value, never a column or operator.

```php
Database::table('posts')
    ->select('posts.*', 'users.name as author')
    ->join('users', 'users.id', '=', 'posts.user_id')
    ->where('published', 1)->whereIn('category_id', $ids)
    ->whereDate('created_at', '>=', now()->subDays(7))
    ->whereHas(...)                          // models only, see below
    ->orderByDesc('created_at')->paginate(20);

Database::table('users')->where(fn ($q) => $q->where('a', 1)->orWhere('b', 2));
Database::table('users')->whereExists(fn ($q) => $q->from('logins')->whereColumn('logins.user_id', 'users.id'));
Database::table('users')->whereIn('id', fn ($q) => $q->from('admins')->select('user_id'));
Database::table('orders')->selectRaw('customer_id, SUM(total) AS spent')->groupBy('customer_id')->having('spent', '>', 100)->get();
Database::table('t')->when($search, fn ($q, $s) => $q->whereLike('name', "%$s%"));
Database::table('t')->union($other)->lockForUpdate()->inRandomOrder();
```

Reading: `get()` (Collection), `first()`, `firstOrFail()`, `sole()`, `find()`,
`findMany()`, `value()`, `pluck()`, `count()/sum()/avg()/min()/max()`,
`exists()`, `chunk(200, fn)`, `chunkById()`, `each()`, `cursor()` (generator),
`paginate()`, `simplePaginate()`, `toSql()`, `toRawSql()`, `dd()`.

Writing: `insert()`, `insertGetId()`, `insertOrIgnore()`, `upsert($rows, 'email')`,
`update()`, `updateOrInsert()`, `increment()`, `decrement()`, `delete()`,
`truncate()`. `Database::raw('NOW()')` marks a trusted fragment.

### Collections

Queries return a `Collection`: `->map()`, `->filter()`, `->pluck('name', 'id')`,
`->keyBy('id')`, `->groupBy('team.name')`, `->sortBy()`, `->where('age', '>', 18)`,
`->sum('total')`, `->chunk()`, `->unique()`, `->first(fn)`, `->each()`,
`->toArray()`, `->toJson()`, and about forty more. `collect([...])` builds one.
Collections iterate in `{foreach}` and count with `count()`.

### Pagination

`paginate()` returns a `Paginator`: iterate it, read `->total()`, `->lastPage()`,
`->nextPageUrl()`, append parameters with `->appends([...])`, and render
Bootstrap 5 links with `{$posts->links()|raw}`. JSON output matches the usual
`data / current_page / last_page / …` shape. `simplePaginate()` skips the COUNT.

### Models

```php
class Post extends Model
{
    use SoftDeletes;

    protected static string $table = 'posts';              // default: snake_case plural of class
    protected static array $fillable = ['title', 'body', 'meta', 'published_at'];
    protected static array $hidden = ['secret'];           // left out of toArray()/JSON
    protected static array $appends = ['excerpt'];         // accessors included in toArray()
    protected static array $casts = ['meta' => 'array', 'published_at' => 'datetime', 'views' => 'int'];
    protected static array $with = ['author'];             // always eager loaded

    public function author(): BelongsTo { return $this->belongsTo(User::class); }      // user_id
    public function comments(): HasMany { return $this->hasMany(Comment::class); }   // comments.post_id
    public function tags(): BelongsToMany { return $this->belongsToMany(Tag::class)->withPivot('note')->withTimestamps(); }

    public function getExcerptAttribute(): string { return str_limit($this->body, 80); }   // $post->excerpt
    public function setTitleAttribute($value): void { $this->attributes['title'] = trim($value); }
    public function scopePublished(QueryBuilder $q): void { $q->whereNotNull('published_at'); }  // Post::published()

    protected static function boot(): void
    {
        static::creating(fn (Post $post) => $post->slug ??= str_slug($post->title));
        static::addGlobalScope('recent', fn ($q) => $q->where('created_at', '>', '2020-01-01'));
    }
}
```

Reading and writing:

```php
Post::find(1);  Post::findOrFail(1);  Post::all();  Post::published()->latest()->get();
$post = Post::create([...]);  $post->update([...]);  $post->delete();  $post->refresh();
Post::firstOrCreate(['slug' => $slug], [...]);  Post::updateOrCreate([...], [...]);
Post::destroy([1, 2]);  $post->replicate();  $post->increment('views');
$post->isDirty('title');  $post->getOriginal('title');  $post->wasChanged();
$post->title;  $post['title'];  $post->published_at->diffForHumans();  $post->toArray();
```

Casts: `int`, `float`, `decimal:2`, `string`, `bool`, `array`/`json`, `object`,
`collection`, `date`, `datetime`, `timestamp`. Timestamp columns are
`DateTimeValue` objects that print as `Y-m-d H:i:s` and offer
`->addDays()`, `->diffForHumans()`, `->isPast()`, `->toDateString()`, …

Relationships:

```php
$post->author;  $post->comments;  $post->tags;             // lazy, cached on the model
$post->comments()->where('approved', 1)->get();          // relation as a query
$post->comments()->create(['body' => '...']);            // sets post_id
$comment->post()->associate($post);
$post->tags()->attach([1, 2 => ['note' => 'x']]);  ->detach();  ->sync([1, 3]);  ->toggle([2]);
$tag->pivot->note;

Post::with('author', 'comments.user')->get();            // one query per relation, no N+1
Post::with(['comments' => fn ($q) => $q->latest()])->get();
$posts->load('tags');  $post->loadMissing('author');
Post::has('comments')->get();  Post::has('comments', '>=', 3)->get();
Post::whereHas('comments', fn ($q) => $q->where('approved', 1))->get();
Post::doesntHave('tags')->get();  Post::withCount('comments')->get();   // ->comments_count
```

Events (`creating`, `created`, `updating`, `updated`, `saving`, `saved`,
`deleting`, `deleted`, `restoring`, `restored`, `retrieved`):
`Post::created(fn ($post) => ...)`, `Post::observe(PostObserver::class)`.
Returning `false` from a `*ing` listener cancels the operation.
`Model::withoutEvents(fn () => ...)`, `$post->saveQuietly()`.

Soft deletes (`use SoftDeletes;`, table needs `softDeletes()`):
`$post->delete()` stamps `deleted_at`; `Post::withTrashed()`,
`Post::onlyTrashed()`, `$post->restore()`, `$post->forceDelete()`,
`$post->trashed()`.

Multiple connections: `protected static ?string $connection = 'sqlite';` or
`Post::on('sqlite')->get()`.

### Schema builder and migrations

`php console.php make:migration create_posts_table` writes a PHP migration:

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150)->unique();
            $table->text('body')->nullable();
            $table->enum('status', ['draft', 'live'])->default('draft')->index();
            $table->decimal('price', 10, 2)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
```

`Schema::table('posts', fn (Blueprint $t) => $t->string('slug')->nullable()->after('title'))`,
`$t->dropColumn()`, `$t->renameColumn()`, `$t->dropIndex([...])`, `$t->dropForeign([...])`,
`->change()` (MySQL). `Schema::hasTable()`, `hasColumn()`, `getColumns()`,
`getTables()`, `rename()`, `dropAllTables()`. Column types: the usual integers,
`string`, `char`, `text`, `json`, `uuid`, `boolean`, `decimal`, `float`, `date`,
`dateTime`, `timestamp`, `enum`, `ipAddress`, … with `nullable()`, `default()`,
`unsigned()`, `unique()`, `index()`, `comment()`, `useCurrent()`.

`id()` and `foreignId()` are both `INT UNSIGNED`, matching the existing tables.
SQL migrations (`--sql`, `-- up` / `-- down` sections) still work. Both MySQL
and SQLite grammars are supported; SQLite cannot modify columns or add
constraints to existing tables.

`migrate`, `migrate:rollback [--step=N]`, `migrate:reset`, `migrate:fresh
[--seed]` (asks first; `--force` skips), `migrate:status`, `db:show`,
`db:table users`, `db:seed [--class=Name]`.

### Factories and seeders

```php
// database/factories/PostFactory.php  (php console.php make:factory Post)
class PostFactory extends Factory
{
    protected string $model = 'Post';

    public function definition(): array
    {
        return [
            'title' => fake()->unique()->sentence(4),
            'body' => fake()->paragraphs(3, true),
            'user_id' => User::factory(),            // nested factory → key
            'views' => fake()->number(0, 500),
        ];
    }
}

Post::factory()->count(20)->create();
Post::factory()->state(['published_at' => now()])->sequence(['views' => 1], ['views' => 2])->make();
```

`fake()` generates names, emails, sentences, numbers, dates, UUIDs, addresses,
etc.; `fake()->unique()->email()` never repeats; `fake()->seed(42)` makes runs
reproducible. Seeders in `database/seeders/*.php` return a closure and run with
`db:seed` (or `migrate --seed`).

## Container

`app()` is a dependency injection container with reflection-based
auto-wiring. Anything with a resolvable constructor is built on demand;
interfaces and shared services are declared in `config/container.php`.

```php
app()->bind(PaymentGateway::class, StripeGateway::class);             // fresh each time
app()->singleton(Mailer::class, fn (Container $app) => new Mailer(config('mail')));
app()->when(ReportController::class)->needs(Cache::class)->give(FileCache::class);
app(ReportBuilder::class);                        // constructor dependencies resolved recursively
app()->call([$service, 'run'], ['limit' => 10]);   // method injection, extra arguments by name or position
```

Controllers, class-based middleware (`middleware('admin', AdminMiddleware::class)`
with a `handle(callable $next, ...$params)` method) and policies are all
resolved through the container. `ContainerException` names the unresolvable
parameter or the circular dependency.

## Authorization

Abilities are answered by a policy for the model class of the first argument
(`policies/PostPolicy.php`, found by name, or listed in `config/auth.php`) or
by a standalone definition in `policies/gates.php`.

```php
// policies/PostPolicy.php  (php console.php make:policy Post)
class PostPolicy
{
    public function before(User $user, string $ability): ?bool { return $user->is_admin ? true : null; }
    public function view(?User $user, Post $post): bool { return $post->published; }   // ?User admits guests
    public function update(User $user, Post $post): bool { return $post->user_id === $user->id; }
}

// policies/gates.php
gate_define('admin', fn (User $user) => (bool) $user->is_admin);
gate_before(fn (?User $user) => $user?->is_superuser ? true : null);
```

Checks: `can('update', $post)`, `cannot()`, `can_any([...])`,
`authorize('update', $post)` (throws a 403), `$user->can('update', $post)`,
`gate()->forUser($other)->allows(...)`. In templates: `{if 'update'|can:$post}`.
On routes: `['can:admin']`, `['can:update,Post@id']` (model from the route
parameter), `['can:create,Post']` (class name). Policy constructors are
injected by the container. A callback whose first parameter is not nullable
is never called for guests; the ability is simply denied.

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
on login, and expire after `SESSION_LIFETIME` idle minutes. `SESSION_DRIVER`
picks the storage: `file` (default), `database` (the `sessions` table:
`session:table`, then `migrate`), `cookie` (encrypted with `APP_KEY`, 4 KB
limit) or `array` (memory, for tests). `flash('success',
'Saved')` shows on the next request via `views/includes/alerts.tpl`;
`flash_now()` for the current one. `cache_remember('key', 3600, fn () => …)`,
`cache_get/set/forget/flush`. `['throttle:5,1']` limits a route to 5 requests
per minute per IP and answers 429 with `Retry-After`. Per-route HTTP caching:
`['cache.headers:public,max_age=3600,etag']` (Cache-Control, ETag, 304 on
`If-None-Match`/`If-Modified-Since`) and `['cache.response:600']` (whole GET
responses in the file cache, skipped for logged-in visitors and flash).

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
`APP_DEBUG=true` the exception page shows the code excerpt, chained causes, the
stack trace (deliberately without argument values) and the query log.

## Console

```
serve [host:port]        route:list  route:cache  route:clear
migrate [--seed]  migrate:rollback [--step=N]  migrate:reset  migrate:fresh [--seed] [--force]  migrate:status
db:seed [--class=]  db:show [connection]  db:table <name>
make:controller  make:model [-m] [-f] [-c]  make:factory  make:middleware  make:migration [--create=|--table=|--sql]  make:policy [--model=]  make:seeder
make:crud Name [--fields=title:string,body:text] [--force] [--routes]
view:clear  cache:clear  key:generate  test [filter]
```

## Tests

`tests/*.php` files call `test('name', fn () => …)` with `assert_same`,
`assert_true`, `assert_contains`, `assert_throws`, `skip()`, and so on (see
`core/testing.php`). `before_each()`/`after_each()` hooks run around every
test in a file. State is reset between tests.

The HTTP client runs requests in-process through the real middleware,
controllers and views, and keeps the session between calls like a browser:

```php
before_each(function () {
    use_test_database();        // in-memory SQLite with the app's migrations
    http_use_app_routes();      // routes/web.php
    Database::beginTransaction();
});
after_each(fn () => Database::rollBack());

test('login works', function () {
    $user = User::factory()->create(['email' => 'a@b.co']);
    http_post('/login', ['email' => 'a@b.co', 'password' => 'password'])
        ->assertRedirect('/account')->assertAuthenticated($user);
    http_get('/account')->assertOk()->assertSee($user->name);
    http_json('GET', '/api/ping')->assertJsonPath('user.email', 'a@b.co');
});
```

`http_get/post/put/patch/delete/json()`, `http_follow()`, `acting_as($user)`;
assertions include `assertStatus/Ok/NotFound/Forbidden`, `assertRedirect('/x')`,
`assertRedirectToRoute()`, `assertSee()`, `assertJson()`, `assertJsonPath()`,
`assertSessionHas()`, `assertSessionHasErrors()`, `assertValid()`,
`assertAuthenticated()`, `assertGuest()`; plus `assert_database_has()`,
`assert_database_missing()`, `assert_database_count()`. CSRF tokens are added
automatically; pass `['_token' => 'x']` to test the failure. The response's
`->exception` holds anything an action threw.

`php console.php test` loads the `pdo_sqlite` extension automatically when it
is installed but not enabled. Without it the database tests are skipped (set
`DB_TEST_CONNECTION` to a throwaway connection to run them elsewhere).

## Performance

Every response carries an `X-Response-Time` header, and with `APP_DEBUG=true`
the layout footer prints the render time, memory and query count. Two console
commands measure the framework:

```bash
php -d opcache.enable_cli=1 console.php bench                 # in-process: routing, queries, views, full requests
php console.php bench:http http://localhost/myapp/ --requests=1000 --concurrency=10   # end to end over HTTP (add --ab for ApacheBench)
```

Reference numbers and methodology are in `docs/performance.html`. A measured
comparison against Laravel, Symfony and plain PHP on the same machine, with a
setup script to reproduce it, lives in `docs/comparison.html` and `benchmarks/`:

```bash
bash benchmarks/setup.sh /path/to/bench          # installs Laravel + Symfony with the same routes
php console.php bench:compare comfree=http://localhost/app/ laravel=http://localhost/bench/laravel/public/bench/page
```

## Queue

Jobs are classes in `jobs/` extending `Job` with a `handle()` method (scaffold
with `make:job`); `dispatch(new SendWelcomeEmail($user->id))` pushes one,
`dispatch_later(300, $job)` delays it, `dispatch_sync($job)` runs it inline.
`QUEUE_CONNECTION=sync` (default) executes jobs immediately; `database` stores
them in the `jobs` table (run `migrate`) for `php console.php queue:work`,
which retries per the job's `$tries`/`$backoff`, moves exhausted jobs to
`failed_jobs` and calls `failed()`. Inspect them with `queue:failed`,
`queue:retry <id|all>`, `queue:forget`, `queue:flush` and `queue:size`.
Supervise the worker with systemd or drain it from cron with
`--stop-when-empty`; in tests `Queue::fake()` plus `Queue::assertPushed()`
records dispatches without running them. Details: `docs/queue.html`.

## Encryption and signed URLs

`APP_KEY` drives an AES-256-GCM encrypter: `encrypt($value)` / `decrypt($payload)`
(throws `DecryptException` on a tampered or foreign payload),
`encrypt_string` / `decrypt_string`. `cookie('theme', 'dark', $minutes)` writes
an encrypted, HttpOnly cookie and `cookie_get('theme')` reads it back (null when
tampered); `cookie_forget()`. `signed_url('unsubscribe', ['id' => 5], 3600)` /
`signed_path()` append an HMAC `signature` (and `expires`) to a named route's
URL; the `signed` middleware answers 403 for an invalid or expired link.
`php console.php security:check` audits APP_KEY, debug mode, `.env` exposure,
writable directories, cookie/HSTS/CSP settings and database credentials, and
exits 1 on any failure.

## Events

`listen('user.registered', fn ($user) => ...)` registers a listener and
`event('user.registered', $user)` runs every match (wildcards such as
`'user.*'`, priorities, object events by class name) and returns their
results; `event_until()` stops at the first non-null one. Class listeners
in `listeners/` are built by the container and wired up in
`config/events.php`; a listener implementing `ShouldQueue` runs on the
queue when that module is installed. `php console.php make:listener Name
--event=...` scaffolds one and `Event::fake()` / `Event::assertDispatched()`
cover tests. Docs: `docs/events.html`.

## Mail

Emails are `Mailable` classes in `mail/` (`php console.php make:mail Name`)
rendered from Smarty templates in `views/mail/`, sent with
`Mail::to($address)->send(new WelcomeMail($name))`, `Mail::raw()` or
`Mail::queue()`. Transports in `config/mail.php`: a raw-socket SMTP client
(STARTTLS, AUTH LOGIN/PLAIN, attachments), PHP `mail()`, the log file
(development default) and an in-memory array. `Mail::fake()` with
`Mail::assertSent(WelcomeMail::class, fn ($m) => $m->hasTo(...))` covers
tests, and `php console.php mail:test you@example.com` checks a live
setup. Docs: `docs/mail.html`.

## Debug toolbar

With `APP_DEBUG=true` every HTML page ends with a collapsible panel: response
time, peak memory, queries (with bindings and time; slow ones highlighted per
`DB_SLOW_QUERY_MS`), the matched route and its middleware, method/path/status,
the user id, included files and the log lines of the request. Session keys
are listed with truncated values; tokens and secrets are hidden. It is
injected by `send_response()` before `</body>`, only for HTML pages with a
2xx/3xx status that are not redirects, with inline CSP-nonced assets. Hide it
with `?_toolbar=0`, `toolbar_disable()` in an action, or `APP_TOOLBAR=false`
(`config('app.toolbar')`). Off entirely when `APP_DEBUG` is false.

## Scaffolding a resource

```bash
php console.php make:crud Post --fields=title:string,body:text,published:boolean,summary:text?
```

Writes `models/Post.php` (fillable, casts), a `create_posts_table` migration,
`PostFactory`, `PostPolicy` (read for everyone, write for logged-in users),
`PostsController` (index/show/create/store/edit/update/destroy with
`validated()`, route model binding and `authorize()`), five Smarty views in
`views/posts/` extending the layout, and `tests/PostsTest.php`. It prints the
route lines to paste into `routes/web.php` (`--routes` appends them). Types:
`string`, `text`, `integer`, `boolean`, `float`, `date`, `datetime`; `?` makes
a column nullable. Existing files are kept unless `--force` is given.

## Installing

```bash
php install.php                                  # interactive: name, database, key, migrations
php install.php --no-interaction --db=sqlite --migrate
bash create-project.sh ../my-app --db=sqlite     # new app from this checkout, then install
```

`install.php` creates `.env` from `.env.example`, writes the answers into it,
prepares `storage/` and `bootstrap/cache`, runs `key:generate` and optionally
`migrate`. `composer.json` declares no dependencies (PHP and extensions only)
and offers `composer test`, `composer lint` and `composer analyse`.

## CI and static analysis

`.github/workflows/tests.yml` lints every file, installs a SQLite `.env` and
runs the test suite on PHP 8.2, 8.3 and 8.4, then PHPStan. Locally:

```bash
composer global require phpstan/phpstan
phpstan analyse                                  # level 6, phpstan.neon
```

`phpstan.neon` bootstraps `core/bootstrap.php` so the procedural functions
are known, scans `console.php` for the console helpers, and includes
`phpstan-baseline.neon` with the findings in the existing code; regenerate
it with `--generate-baseline` after fixing some. The version is in `VERSION`
and changes are tracked in `CHANGELOG.md`. Details: `docs/tooling.html`.

## Production checklist

- `APP_DEBUG=false`, `APP_ENV=production`, a real `APP_KEY`; run `php console.php security:check`.
- `php console.php route:cache` after deploying route changes; `view:clear`
  after template changes (compile checks are off in production).
- Enable OPcache (`opcache.enable=1`, `opcache.validate_timestamps=0` on
  immutable deploys).
- Serve over HTTPS and set `HSTS_ENABLED=true`; session cookies get the
  `Secure` flag automatically on HTTPS.
- `storage/` must be writable by the web server; everything else read-only.
