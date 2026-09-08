<?php

// Project installer: php install.php [options]
//
// Creates .env from .env.example, fills in the basics, generates APP_KEY,
// makes sure storage/ is writable and optionally runs the migrations. Asks
// questions on an interactive terminal; in scripts pass the answers as flags:
//
//   php install.php --no-interaction --name="My App" --db=sqlite --migrate
//   php install.php --db=mysql --db-database=myapp --db-username=root --db-password=secret
//
// Options: --name --url --env=local|production --db=mysql|sqlite --db-host
// --db-port --db-database --db-username --db-password --migrate --no-migrate
// --skip-key --no-interaction --force (rewrite an existing .env)

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line: php install.php');
}

$root = str_replace('\\', '/', __DIR__);
$options = [];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $options[$key] = $value;
    }
}

$interactive = !isset($options['no-interaction']) && function_exists('stream_isatty') && stream_isatty(STDIN);

function install_line(string $text = ''): void
{
    echo $text, PHP_EOL;
}

function install_fail(string $text): never
{
    fwrite(STDERR, 'Error: ' . $text . PHP_EOL);
    exit(1);
}

// Flag value, else a prompt (interactive only), else the default.
function install_ask(string $flag, string $question, string $default = '', bool $secret = false): string
{
    global $options, $interactive;

    if (isset($options[$flag]) && $options[$flag] !== true) {
        return (string) $options[$flag];
    }

    if (!$interactive) {
        return $default;
    }

    echo $question . ($default !== '' && !$secret ? " [$default]" : '') . ': ';
    $answer = trim((string) fgets(STDIN));

    return $answer === '' ? $default : $answer;
}

function install_confirm(string $flag, string $question, bool $default): bool
{
    global $options, $interactive;

    if (isset($options[$flag])) {
        return true;
    }

    if (isset($options['no-' . $flag])) {
        return false;
    }

    if (!$interactive) {
        return $default;
    }

    echo $question . ($default ? ' [Y/n] ' : ' [y/N] ');
    $answer = strtolower(trim((string) fgets(STDIN)));

    return $answer === '' ? $default : $answer === 'y';
}

// Sets KEY=value in the .env contents, appending when the key is missing.
function install_env_set(string $contents, string $key, string $value): string
{
    if (preg_match('/[\s#"\']/', $value)) {
        $value = '"' . addcslashes($value, '"\\') . '"';
    }

    if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $contents)) {
        return (string) preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $key . '=' . $value, $contents);
    }

    return rtrim($contents) . "\n" . $key . '=' . $value . "\n";
}

function install_console(string $command, array $arguments = []): int
{
    global $root;

    $parts = array_map('escapeshellarg', array_merge([PHP_BINARY, $root . '/console.php', $command], $arguments));
    passthru(implode(' ', $parts), $code);

    return $code;
}

install_line('ComfreePHP installer');
install_line('====================');
install_line();

if (PHP_VERSION_ID < 80200) {
    install_fail('PHP 8.2 or newer is required; this is ' . PHP_VERSION . '.');
}

foreach (['pdo', 'mbstring', 'openssl'] as $extension) {
    if (!extension_loaded($extension)) {
        install_fail("The $extension extension is required.");
    }
}

// 1. .env ---------------------------------------------------------------

$envFile = $root . '/.env';
$example = $root . '/.env.example';

if (is_file($envFile) && !isset($options['force'])) {
    install_line('.env already exists, keeping it (pass --force to rewrite it from .env.example).');
    $contents = (string) file_get_contents($envFile);
    $fresh = false;
} else {
    if (!is_file($example)) {
        install_fail('.env.example is missing.');
    }

    $contents = (string) file_get_contents($example);
    $fresh = true;
}

if ($fresh) {
    $contents = install_env_set($contents, 'APP_NAME', install_ask('name', 'Application name', 'ComfreePHP'));
    $contents = install_env_set($contents, 'APP_ENV', install_ask('env', 'Environment (local/production)', 'local'));
    $contents = install_env_set($contents, 'APP_DEBUG', install_ask('debug', 'Debug mode (true/false)', 'true'));
    $contents = install_env_set($contents, 'APP_URL', install_ask('url', 'Application URL (leave empty to derive it per request)', ''));

    $driver = strtolower(install_ask('db', 'Database driver (mysql/sqlite)', 'mysql'));

    if (!in_array($driver, ['mysql', 'sqlite'], true)) {
        install_fail("Unknown database driver [$driver]; use mysql or sqlite.");
    }

    $contents = install_env_set($contents, 'DB_CONNECTION', $driver);

    if ($driver === 'mysql') {
        $contents = install_env_set($contents, 'DB_HOST', install_ask('db-host', 'MySQL host', '127.0.0.1'));
        $contents = install_env_set($contents, 'DB_PORT', install_ask('db-port', 'MySQL port', '3306'));
        $contents = install_env_set($contents, 'DB_DATABASE', install_ask('db-database', 'Database name', ''));
        $contents = install_env_set($contents, 'DB_USERNAME', install_ask('db-username', 'Database user', 'root'));
        $contents = install_env_set($contents, 'DB_PASSWORD', install_ask('db-password', 'Database password', '', true));
    } else {
        $contents = install_env_set($contents, 'DB_SQLITE_DATABASE', install_ask('db-database', 'SQLite file (relative to the project)', 'storage/database.sqlite'));
    }

    file_put_contents($envFile, $contents);
    install_line('Wrote .env');
}

// 2. storage ------------------------------------------------------------

foreach (['storage', 'storage/logs', 'storage/cache', 'storage/cache/smarty', 'storage/views', 'bootstrap/cache'] as $directory) {
    $path = $root . '/' . $directory;

    if (!is_dir($path) && !@mkdir($path, 0755, true)) {
        install_fail("Could not create $directory.");
    }

    if (!is_writable($path)) {
        install_fail("$directory is not writable by " . (function_exists('get_current_user') ? get_current_user() : 'this user') . '.');
    }
}

install_line('Storage directories are in place and writable.');

// 3. APP_KEY ------------------------------------------------------------

if (!isset($options['skip-key'])) {
    if (preg_match('/^APP_KEY=\S+/m', (string) file_get_contents($envFile))) {
        install_line('APP_KEY is already set.');
    } elseif (install_console('key:generate') !== 0) {
        install_fail('key:generate failed.');
    }
}

// 4. migrations ---------------------------------------------------------

$migrated = false;

if (install_confirm('migrate', 'Run the database migrations now?', false)) {
    $migrated = install_console('migrate') === 0;

    if (!$migrated) {
        install_line('Migrations failed; check the DB_* settings in .env and run `php console.php migrate` again.');
    }
}

// 5. next steps ---------------------------------------------------------

install_line();
install_line('Done. Next steps:');

if (!$migrated) {
    install_line('  php console.php migrate            # create the tables');
}

install_line('  php console.php serve              # http://127.0.0.1:8000');
install_line('  php console.php test               # run the test suite');
install_line('  php console.php make:crud Post --fields=title:string,body:text');
install_line('  docs/index.html                    # the documentation');

exit(0);
