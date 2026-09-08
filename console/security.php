<?php

// Security-related console commands: `security:check` audits the
// deployment configuration, `session:table` provides the migration for
// SESSION_DRIVER=database.

// One row of the security:check report. $status is pass, warn or fail.
function security_check_row(string $status, string $check, string $detail): array
{
    return ['status' => strtoupper($status), 'check' => $check, 'detail' => $detail];
}

// Runs every check and returns the rows; exposed so tests can call it.
function security_check_rows(): array
{
    $rows = [];
    $env = (string) config('app.env', 'production');
    $production = $env === 'production';
    $appUrl = (string) config('app.url', '');
    $https = str_starts_with(strtolower($appUrl), 'https://');

    // APP_KEY: present and at least 32 bytes of real key material.
    $key = (string) config('app.key', '');

    if ($key === '') {
        $rows[] = security_check_row('fail', 'APP_KEY', 'not set — run: php console.php key:generate');
    } else {
        $raw = str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
        $rows[] = strlen($raw) >= 32
            ? security_check_row('pass', 'APP_KEY', strlen($raw) . ' bytes')
            : security_check_row('fail', 'APP_KEY', 'only ' . strlen($raw) . ' bytes; 32 or more required (key:generate)');
    }

    // Debug mode must be off in production: it prints code and paths.
    if ($production && config('app.debug', false)) {
        $rows[] = security_check_row('fail', 'APP_DEBUG', 'true while APP_ENV=production');
    } elseif (config('app.debug', false)) {
        $rows[] = security_check_row('warn', 'APP_DEBUG', 'true (fine for APP_ENV=' . $env . ', never for production)');
    } else {
        $rows[] = security_check_row('pass', 'APP_DEBUG', 'false');
    }

    // .env must not be served. Ask the web server when we know its address,
    // otherwise inspect .htaccess for the dotfile block.
    $rows[] = security_check_env_exposure($appUrl);

    // Writable directories.
    foreach (['storage', 'storage/cache', 'storage/logs', 'bootstrap/cache'] as $directory) {
        $path = BASE_PATH . '/' . $directory;

        if (!is_dir($path)) {
            $rows[] = security_check_row('warn', $directory . '/', 'missing');
        } elseif (!is_writable($path)) {
            $rows[] = security_check_row('fail', $directory . '/', 'not writable');
        } else {
            $rows[] = security_check_row('pass', $directory . '/', 'writable');
        }
    }

    // Cookie and transport hardening once the site is on HTTPS.
    if ($https) {
        $rows[] = config('session.secure', false)
            ? security_check_row('pass', 'Session cookie', 'Secure flag forced (SESSION_SECURE_COOKIE=true)')
            : security_check_row('warn', 'Session cookie', 'APP_URL is https but SESSION_SECURE_COOKIE=false (the flag is still set on HTTPS requests; force it behind proxies)');

        $rows[] = config('security.hsts', false)
            ? security_check_row('pass', 'HSTS', 'enabled')
            : security_check_row('warn', 'HSTS', 'APP_URL is https but HSTS_ENABLED=false');
    } elseif ($production) {
        $rows[] = security_check_row('warn', 'HTTPS', $appUrl === '' ? 'APP_URL not set; cannot tell whether the site is served over TLS' : 'APP_URL is not https');
    } else {
        $rows[] = security_check_row('pass', 'HTTPS', 'not required for APP_ENV=' . $env);
    }

    // Content-Security-Policy.
    $csp = (array) config('security.csp', []);

    if ($csp === []) {
        $rows[] = security_check_row('warn', 'CSP', 'no Content-Security-Policy configured (config/security.php)');
    } elseif (!isset($csp['default-src'])) {
        $rows[] = security_check_row('warn', 'CSP', 'default-src missing');
    } else {
        $rows[] = security_check_row('pass', 'CSP', count($csp) . ' directives');
    }

    // Default database credentials.
    $default = (string) config('database.default', 'mysql');
    $connection = (array) config('database.connections.' . $default, []);

    if (($connection['driver'] ?? '') === 'mysql') {
        $rows[] = ($connection['username'] ?? '') === 'root' && ($connection['password'] ?? '') === ''
            ? security_check_row($production ? 'fail' : 'warn', 'Database', 'root without a password on [' . $default . ']')
            : security_check_row('pass', 'Database', 'dedicated credentials on [' . $default . ']');
    } else {
        $rows[] = security_check_row('pass', 'Database', ($connection['driver'] ?? 'unknown') . ' on [' . $default . ']');
    }

    // Session driver sanity.
    $driver = (string) config('session.driver', 'file');

    if ($driver === 'array') {
        $rows[] = security_check_row('warn', 'Session driver', 'array: sessions do not survive a request');
    } elseif ($driver === 'cookie' && $key === '') {
        $rows[] = security_check_row('fail', 'Session driver', 'cookie driver needs APP_KEY');
    } else {
        $rows[] = security_check_row('pass', 'Session driver', $driver);
    }

    return $rows;
}

function security_check_env_exposure(string $appUrl): array
{
    if ($appUrl !== '') {
        $url = rtrim($appUrl, '/') . '/.env';
        $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false]]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        if ($body === false && $status === 0) {
            // Server unreachable from here: fall through to the static check.
        } elseif ($status === 200 && str_contains((string) $body, 'APP_KEY')) {
            return security_check_row('fail', '.env over HTTP', $url . ' is readable');
        } elseif ($status === 200) {
            return security_check_row('warn', '.env over HTTP', $url . ' answered 200 (probably the app catch-all; verify manually)');
        } else {
            return security_check_row('pass', '.env over HTTP', $url . ' answered ' . $status);
        }
    }

    $htaccess = BASE_PATH . '/.htaccess';

    if (!is_file($htaccess)) {
        return security_check_row('warn', '.env over HTTP', 'no .htaccess; make sure the web server blocks dotfiles');
    }

    $contents = (string) file_get_contents($htaccess);

    return preg_match('/FilesMatch\s+"\^\\\\\."/', $contents) && str_contains($contents, 'denied')
        ? security_check_row('pass', '.env over HTTP', '.htaccess denies dotfiles' . ($appUrl === '' ? ' (set APP_URL to test over HTTP)' : ''))
        : security_check_row('warn', '.env over HTTP', '.htaccess has no dotfile block');
}

command('security:check', 'Audit the deployment configuration (APP_KEY, debug, .env exposure, cookies, CSP, DB credentials)', function () {
    $rows = security_check_rows();

    print_table(['Status', 'Check', 'Detail'], $rows);

    $failed = count(array_filter($rows, fn ($row) => $row['status'] === 'FAIL'));
    $warned = count(array_filter($rows, fn ($row) => $row['status'] === 'WARN'));

    line();
    line(($failed === 0 ? 'OK' : 'FAILED') . " — $failed failed, $warned warnings, " . (count($rows) - $failed - $warned) . ' passed');

    return $failed === 0 ? 0 : 1;
});

command('session:table', 'Provide the migration for SESSION_DRIVER=database', function () {
    $existing = glob(BASE_PATH . '/database/migrations/*_create_sessions_table.php') ?: [];

    if ($existing !== []) {
        line('Migration exists: ' . relative_path($existing[0]));
        line('Run `php console.php migrate` and set SESSION_DRIVER=database.');

        return 0;
    }

    $stub = <<<'PHP'
<?php

// Storage for SESSION_DRIVER=database (see DatabaseSessionHandler).

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->longText('payload');
            $table->integer('last_activity')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};

PHP;

    write_stub(BASE_PATH . '/database/migrations/' . date('Y_m_d_His') . '_create_sessions_table.php', $stub);
    line('Run `php console.php migrate` and set SESSION_DRIVER=database.');

    return 0;
});
