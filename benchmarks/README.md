# Framework comparison benchmarks

Everything needed to reproduce the comparison published in
`docs/comparison.html`: equivalent endpoints for Laravel, Symfony and plain
PHP, a setup script, and the `bench:compare` console command.

## What is compared

| Endpoint | ComfreePHP | Laravel | Symfony | Raw PHP |
| --- | --- | --- | --- | --- |
| Page: layout, navigation, 4-item loop, form with CSRF token, session started | `/` (the real home page) | `/bench/page` (web middleware group) | `/bench/page` | `/page.php` |
| JSON: `{"pong": true, "time": "..."}` | `/api/ping` | `/bench/json` (no middleware group) | `/bench/json` | `/json.php` |
| Info: files included, peak memory | add the route shown in the docs while measuring | `/bench/info` | `/bench/info` | `/info.php` |

All applications run in production mode with warmed caches (`artisan
optimize`, `cache:warmup`, an authoritative class map), on the same Apache,
PHP and OPcache. Nothing touches a database.

## Setup

```bash
bash benchmarks/setup.sh /var/www/bench        # or C:/xampp/htdocs/bench
```

The script installs Laravel and Symfony with Composer (internet required),
copies the benchmark routes and templates from this directory, switches both
to production mode (Laravel: file sessions and cache; Symfony: `APP_ENV=prod`
with the `apache-pack` recipe so `public/.htaccess` exists) and warms their
caches. Re-running it refreshes the files without reinstalling.

## Run

```bash
php console.php bench:compare \
    comfree=http://localhost/app/ \
    laravel=http://localhost/bench/laravel/public/bench/page \
    symfony=http://localhost/bench/symfony/public/bench/page \
    raw=http://localhost/bench/raw/page.php \
    --requests=1000 --concurrency=10 --runs=3
```

`bench:compare` uses a built-in load client on PHP's curl extension, so no
external tool is needed. Targets are warmed first, then hit in alternating
rounds so that background noise affects all of them equally; the table shows
the median of the runs, the 50th, 95th and 99th percentile latency, failures,
response size and throughput relative to the fastest target. Options:
`--concurrency=1` for pure latency, `--ab` to measure with ApacheBench
instead (its "Length" failures caused by random-length CSRF tokens are
subtracted), `--json` for machine-readable output.

## Before trusting any number

- Check OPcache is not full: `opcache_get_status()['cache_full']` must be
  `false`, otherwise the frameworks are being recompiled on every request and
  every result is wrong. The XAMPP default of 128 MB fills up quickly when
  several projects share one Apache.
- Use identical `--requests` and `--concurrency`, and at least three runs.
- Keep the client on another machine if you can; a shared client lowers every
  number equally but compresses the gap between the fastest targets.
- Windows with real-time antivirus scanning penalises anything that writes
  files per request (sessions, caches); Linux numbers are higher across the
  board.
- These are micro-benchmarks of framework overhead. A real application is
  dominated by database and network time, where all frameworks are equal.
