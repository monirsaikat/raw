# Changelog

All notable changes to ComfreePHP are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/); the current version is in `VERSION`.

## [Unreleased]

### Added

- Localization module: `__()`, `trans()`, `trans_choice()` with plural
  ranges, `lang/<locale>/<group>.php` and `lang/<locale>.json` files,
  fallback locale, `app_locale()` / `set_locale()`, the global `locale`
  middleware (`?lang=`, session, `Accept-Language`), Smarty `{t}` tag and
  `__` / `trans_choice` modifiers, `$app_locale` and `$app_env` template
  globals, validation messages and field names from
  `lang/<locale>/validation.php`, translated paginator labels, and the
  `make:lang`, `lang:missing` and `lang:list` commands. `docs/localization.html`.
- `auth.login_path`: the `auth` middleware redirects there when the app has
  no route named `auth.login_route`.
- Multiple auth guards: `config/auth.php` gains `default` and `guards`; every
  `auth_*()` helper takes an optional trailing `$guard`, `guard('admin')`
  wraps them in an object, `auth_use_guard()` / the `guard:name` middleware
  set the request default, `auth:name` and `guest:name` middleware, per-guard
  session keys, intended URLs and remember-me cookies, `auth_logout_all()`,
  `acting_as($user, $guard)` and `assertAuthenticated($user, $guard)` /
  `assertGuest($guard)` in tests.

### Changed

- The skeleton app is now a single starter route (`/` → `HomeController`,
  `views/home.tpl`) with a next-steps page. The demo about/contact/user/auth
  pages, the `Message` model and its migration were removed; the two users
  migrations were merged into one that includes `remember_token` and
  `timestamps()`.

### Removed

- The `benchmarks/` folder (Laravel/Symfony comparison sources); the numbers
  and method stay in `docs/comparison.html`.

- Debug toolbar on HTML pages when `APP_DEBUG` is on: time, memory, matched
  route, request and status, query log with slow-query highlighting, session
  keys (secrets hidden), user id, included files and the log lines of the
  request. Inline, CSP-nonced assets; `?_toolbar=0`, `toolbar_disable()` and
  `config('app.toolbar')` switch it off. `log_buffer()` exposes the in-memory
  log entries.
- `make:crud Name --fields=...`: scaffolds model, migration, factory, policy,
  controller, Smarty views, a test file and prints (or `--routes` appends) the
  route lines.
- `install.php` project installer and `create-project.sh` to start a new
  application from a checkout.
- GitHub Actions workflow (`php -l`, the test suite on PHP 8.2/8.3/8.4, and
  PHPStan), `phpstan.neon` at level 6 with a baseline, and a dependency-free
  `composer.json` with `test`, `lint` and `analyse` scripts.
- `VERSION` file and this changelog; `docs/tooling.html`.
- Events module: `listen()` / `event()` with wildcards and priorities, class
  listeners in `listeners/`, queued listeners, `make:listener`, `Event::fake()`.
- Queue module: `Job` classes in `jobs/`, sync and database drivers,
  `queue:work`, failed-job commands, `make:job`, `Queue::fake()`.
- Mail module: `Mailable` classes, SMTP / sendmail / log / array transports,
  `Mail::fake()`, `make:mail`, `mail:test`.
- Encryption (`encrypt()` / `decrypt()`, encrypted cookies), signed URLs with
  the `signed` middleware, and `security:check`.
- HTTP caching middleware (`cache.headers`, `cache.response`) and session
  drivers (`file`, `database`, `cookie`, `array`) with `session:table`.

### Changed

- `send_response()` now normalises every action result to a `Response`
  before sending so response filters (the toolbar) see one object.

## [0.9.0] - 2026-09-08

The first versioned snapshot; everything below was built up in the git
history before the version file existed.

### Added

- Routing with static-table lookup, compiled dynamic patterns, groups,
  optional and constrained parameters, method spoofing, 405 handling,
  named routes, `route:cache` for production and `route:list`.
- Controllers built by a reflection-based DI container with route model
  binding; class and closure middleware; `csrf`, `auth`, `guest`,
  `throttle` and `can` middleware.
- PDO database layer for MySQL/MariaDB and SQLite: a query builder with
  joins, sub-queries, unions, locks and pagination, typed bindings, query
  log and slow-query logging, transactions with savepoints and retries.
- Models with fillable/hidden/casts, accessors, scopes, events, soft deletes,
  relationships (`hasOne`, `hasMany`, `belongsTo`, `belongsToMany`) with eager
  loading, collections with forty-odd helpers, paginators with Bootstrap links.
- Schema builder and migrator (PHP and SQL migrations, MySQL and SQLite
  grammars), factories with a built-in fake data generator, seeders.
- Smarty 5 views (vendored) with auto-escaping, layout, shared globals and
  template tags for CSRF, URLs, assets and the CSP nonce.
- Validation with thirty rules and custom rules, flash messages, old input.
- Authentication with hashed passwords, remember-me tokens, intended URL;
  authorization with policies and gates.
- Sessions with lazy start and rotation, file cache, rate limiting.
- Security headers, Content-Security-Policy with nonces, HSTS, directory
  lockdown, `.env` configuration.
- Error handling with a debug page (code excerpt, chained causes, query log),
  JSON errors and custom error templates; PSR-3-style file logging.
- Console with `serve`, migrations, seeding, database inspection, generators
  (`make:controller`, `make:model`, `make:migration`, `make:factory`,
  `make:policy`, `make:middleware`, `make:seeder`), cache commands,
  `key:generate` and the test runner.
- A dependency-free test runner with an in-process HTTP client, in-memory
  SQLite database, database assertions and around 260 tests.
- Benchmarks: `bench` (in-process), `bench:http` (built-in load client or
  ApacheBench) and `bench:compare` against Laravel, Symfony and plain PHP,
  with the numbers published in the docs.
- HTML documentation site in `docs/` and a comprehensive README.
- Rebranded the project to ComfreePHP.

### Fixed

- Base-path detection on Windows sub-directory installs; route caching and
  autoloading edge cases.

[Unreleased]: https://github.com/comfreephp/comfreephp/compare/v0.9.0...HEAD
[0.9.0]: https://github.com/comfreephp/comfreephp/releases/tag/v0.9.0
