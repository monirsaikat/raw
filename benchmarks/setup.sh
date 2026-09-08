#!/usr/bin/env bash
# Builds the comparison applications next to each other so the same Apache
# and PHP can serve all of them:
#
#   <root>/raw/       plain PHP baseline
#   <root>/laravel/   fresh Laravel with bench routes, production mode, caches warmed
#   <root>/symfony/   fresh Symfony with Twig + CSRF + Apache pack, prod mode, caches warmed
#
# Usage: bash benchmarks/setup.sh /path/to/bench-root
# Requires composer on the PATH and internet access. Re-running only
# refreshes the benchmark files and caches; existing installs are kept.

set -euo pipefail

ROOT="${1:?Usage: setup.sh <bench-root-directory>}"
HERE="$(cd "$(dirname "$0")" && pwd)"

mkdir -p "$ROOT"

# ---------------------------------------------------------------- raw PHP --
mkdir -p "$ROOT/raw"
cp "$HERE"/raw/*.php "$ROOT/raw/"
echo "raw: ready"

# ---------------------------------------------------------------- Laravel --
if [ ! -d "$ROOT/laravel" ]; then
    composer create-project laravel/laravel "$ROOT/laravel" --no-interaction --prefer-dist --no-progress
fi

cp "$HERE/laravel/routes/bench.php" "$ROOT/laravel/routes/bench.php"

if ! grep -q "ComfreePHP comparison benchmark" "$ROOT/laravel/routes/web.php"; then
    cat "$HERE/laravel/routes/web-append.php" >> "$ROOT/laravel/routes/web.php"
fi

mkdir -p "$ROOT/laravel/resources/views/bench"
cp "$HERE"/laravel/views/*.blade.php "$ROOT/laravel/resources/views/bench/"

# Register routes/bench.php without any middleware group.
php -r '
$file = $argv[1] . "/bootstrap/app.php";
$code = file_get_contents($file);
if (!str_contains($code, "routes/bench.php")) {
    $code = preg_replace(
        "/(web:\s*__DIR__\s*\.\s*\x27\/\.\.\/routes\/web\.php\x27,)/",
        "$1\n        then: fn () => \\\\Illuminate\\\\Support\\\\Facades\\\\Route::group([], base_path(\x27routes/bench.php\x27)),",
        $code,
        1
    );
    file_put_contents($file, $code);
}
' "$ROOT/laravel"

# Production mode, file sessions and cache so no database is involved.
sed -i 's/^APP_ENV=.*/APP_ENV=production/; s/^APP_DEBUG=.*/APP_DEBUG=false/; s/^LOG_LEVEL=.*/LOG_LEVEL=error/; s/^SESSION_DRIVER=.*/SESSION_DRIVER=file/; s/^CACHE_STORE=.*/CACHE_STORE=file/' "$ROOT/laravel/.env"

(cd "$ROOT/laravel" && php artisan optimize --no-interaction)
echo "laravel: ready"

# ---------------------------------------------------------------- Symfony --
if [ ! -d "$ROOT/symfony" ]; then
    SYMFONY_DOCKER=0 composer create-project symfony/skeleton "$ROOT/symfony" --no-interaction --no-progress
    (
        cd "$ROOT/symfony"
        # apache-pack is a contrib recipe: allow it so public/.htaccess gets installed.
        composer config extra.symfony.allow-contrib true
        SYMFONY_DOCKER=0 composer require twig symfony/apache-pack symfony/security-csrf --no-interaction --no-progress
        SYMFONY_DOCKER=0 composer recipes:install symfony/apache-pack --force --no-interaction
    )
fi

mkdir -p "$ROOT/symfony/src/Controller" "$ROOT/symfony/templates/bench"
cp "$HERE/symfony/src/Controller/BenchController.php" "$ROOT/symfony/src/Controller/"
cp "$HERE"/symfony/templates/bench/*.twig "$ROOT/symfony/templates/bench/"
echo "APP_ENV=prod" > "$ROOT/symfony/.env.local"

(
    cd "$ROOT/symfony"
    composer dump-autoload --optimize --classmap-authoritative --no-interaction
    php bin/console cache:clear --no-interaction
    php bin/console cache:warmup --no-interaction
)
echo "symfony: ready"

cat <<EOF

Done. Serve <root> through Apache, then compare, for example:

  php console.php bench:compare \\
      comfree=http://localhost/app/ \\
      laravel=http://localhost/bench/laravel/public/bench/page \\
      symfony=http://localhost/bench/symfony/public/bench/page \\
      raw=http://localhost/bench/raw/page.php \\
      --requests=1000 --concurrency=10 --runs=3

JSON endpoints: /api/ping (ComfreePHP), /bench/json (Laravel, Symfony), /json.php (raw).
Info endpoints: /bench/info (Laravel, Symfony), /info.php (raw).
EOF
