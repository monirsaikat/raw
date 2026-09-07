<?php

// CLI entry point: php console.php <command> [arguments] [--option=value]
// Run without arguments to list the available commands.

if (PHP_SAPI !== 'cli') {
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/core/bootstrap.php';

$commands = [];

function command(string $name, string $description, callable $handler): void
{
    global $commands;

    $commands[$name] = ['description' => $description, 'handler' => $handler];
}

function line(string $text = ''): void
{
    echo $text, PHP_EOL;
}

function error_line(string $text): void
{
    fwrite(STDERR, $text . PHP_EOL);
}

// "a b --key=value --flag -mf" → [['a', 'b'], ['key' => 'value', 'flag' => true, 'm' => true, 'f' => true]]
function parse_arguments(array $args): array
{
    $positional = [];
    $options = [];

    foreach ($args as $arg) {
        if (str_starts_with($arg, '--')) {
            [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
            $options[$key] = $value;
        } elseif (str_starts_with($arg, '-') && strlen($arg) > 1) {
            foreach (str_split(substr($arg, 1)) as $flag) {
                $options[$flag] = true;
            }
        } else {
            $positional[] = $arg;
        }
    }

    return [$positional, $options];
}

// Asks a yes/no question on an interactive terminal; false when not a TTY.
function confirm(string $question): bool
{
    if (!stream_isatty(STDIN)) {
        return false;
    }

    echo $question . ' [y/N] ';

    return strtolower(trim((string) fgets(STDIN))) === 'y';
}

function relative_path(string $path): string
{
    return ltrim(str_replace(BASE_PATH, '', str_replace('\\', '/', $path)), '/');
}

// Writes a generated file, refusing to overwrite an existing one.
function write_stub(string $path, string $contents): bool
{
    if (file_exists($path)) {
        error_line('Already exists: ' . relative_path($path));

        return false;
    }

    $directory = dirname($path);

    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    file_put_contents($path, $contents);
    line('Created ' . relative_path($path));

    return true;
}

function load_routes(): void
{
    routes_reset();

    require BASE_PATH . '/routes/web.php';
}

function print_table(array $headers, array $rows): void
{
    $widths = array_map('strlen', $headers);

    foreach ($rows as $row) {
        foreach (array_values($row) as $i => $cell) {
            $widths[$i] = max($widths[$i], strlen((string) $cell));
        }
    }

    $format = implode('  ', array_map(fn ($w) => '%-' . $w . 's', $widths));

    line(rtrim(sprintf($format, ...$headers)));
    line(rtrim(sprintf($format, ...array_map(fn ($w) => str_repeat('-', $w), $widths))));

    foreach ($rows as $row) {
        line(rtrim(sprintf($format, ...array_map(fn ($c) => (string) $c, array_values($row)))));
    }
}

function migrator(): Migrator
{
    return new Migrator(BASE_PATH . '/database/migrations');
}

function run_seeders(?string $only = null): int
{
    $files = glob(BASE_PATH . '/database/seeders/*.php') ?: [];

    if ($only !== null) {
        $files = array_filter($files, fn ($file) => strcasecmp(basename($file, '.php'), $only) === 0);
    }

    if ($files === []) {
        line($only === null ? 'No seeders found.' : "Seeder [$only] not found.");

        return $only === null ? 0 : 1;
    }

    foreach ($files as $file) {
        $seeder = require $file;

        if (is_callable($seeder)) {
            $seeder();
        }

        line('Seeded: ' . basename($file, '.php'));
    }

    return 0;
}

// ------------------------------------------------------------------------

command('help', 'List the available commands', function () {
    global $commands;

    line('Usage: php console.php <command> [arguments] [--option=value]');
    line();

    $width = max(array_map('strlen', array_keys($commands)));

    foreach ($commands as $name => $command) {
        line('  ' . str_pad($name, $width + 2) . $command['description']);
    }

    return 0;
});

command('serve', 'Start the development server [host:port, default 127.0.0.1:8000]', function (array $args) {
    [$positional] = parse_arguments($args);
    $host = $positional[0] ?? '127.0.0.1:8000';

    line("Serving on http://$host — press Ctrl+C to stop");

    passthru(sprintf(
        '%s -S %s -t %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($host),
        escapeshellarg(BASE_PATH),
        escapeshellarg(BASE_PATH . '/index.php')
    ), $code);

    return $code;
});

command('route:list', 'Show every registered route', function () {
    load_routes();

    $rows = [];

    foreach (routes_all() as $method => $routes) {
        foreach ($routes as $path => $route) {
            $action = $route['action'];
            $rows[] = [
                $method,
                $path,
                $route['name'] ?? '',
                is_string($action) ? $action : (is_array($action) ? implode('@', $action) : 'Closure'),
                implode(', ', $route['middleware']),
            ];
        }
    }

    usort($rows, fn ($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

    print_table(['Method', 'Path', 'Name', 'Action', 'Middleware'], $rows);

    return 0;
});

command('route:cache', 'Compile routes/web.php into bootstrap/cache for production', function () {
    load_routes();

    foreach (routes_all() as $routes) {
        foreach ($routes as $path => $route) {
            if (!is_string($route['action']) && !is_array($route['action'])) {
                error_line("Cannot cache route [$path]: closure actions are not serialisable. Use 'Controller@method'.");

                return 1;
            }
        }
    }

    $file = BASE_PATH . '/bootstrap/cache/routes.php';

    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0755, true);
    }

    $payload = ['routes' => routes_all(), 'names' => route_names()];

    file_put_contents(
        $file,
        "<?php\n\n// Generated by `php console.php route:cache`. Do not edit by hand.\nreturn "
            . var_export($payload, true) . ";\n"
    );

    line('Routes cached to ' . relative_path($file));

    return 0;
});

command('route:clear', 'Delete the compiled route table', function () {
    $file = BASE_PATH . '/bootstrap/cache/routes.php';

    if (is_file($file)) {
        unlink($file);
        line('Route cache cleared.');
    } else {
        line('No route cache to clear.');
    }

    return 0;
});

command('view:clear', 'Delete compiled templates', function () {
    $count = 0;

    foreach (glob((string) config('view.compiled') . '/*.php') ?: [] as $file) {
        if (unlink($file)) {
            $count++;
        }
    }

    line("Removed $count compiled template(s).");

    return 0;
});

command('cache:clear', 'Delete application cache files (including rate-limit counters)', function () {
    line('Removed ' . cache_flush() . ' cache file(s).');

    return 0;
});

command('key:generate', 'Set a random APP_KEY in .env', function () {
    $env = BASE_PATH . '/.env';

    if (!is_file($env)) {
        if (!is_file(BASE_PATH . '/.env.example')) {
            error_line('No .env or .env.example found.');

            return 1;
        }

        copy(BASE_PATH . '/.env.example', $env);
        line('Created .env from .env.example');
    }

    $key = 'base64:' . base64_encode(random_bytes(32));
    $contents = (string) file_get_contents($env);

    if (preg_match('/^APP_KEY=.*$/m', $contents)) {
        $contents = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents);
    } else {
        $contents = rtrim($contents) . "\nAPP_KEY=" . $key . "\n";
    }

    file_put_contents($env, $contents);
    line('Application key set.');

    return 0;
});

// ----------------------------------------------------------------- database --

command('migrate', 'Run pending migrations [--seed]', function (array $args) {
    [, $options] = parse_arguments($args);
    $ran = migrator()->run();

    if ($ran === []) {
        line('Nothing to migrate.');
    }

    foreach ($ran as $name) {
        line("Migrated: $name");
    }

    return isset($options['seed']) ? run_seeders() : 0;
});

command('migrate:rollback', 'Revert the last batch of migrations [--step=N]', function (array $args) {
    [$positional, $options] = parse_arguments($args);
    $steps = max(1, (int) ($options['step'] ?? $positional[0] ?? 1));
    $rolled = migrator()->rollback($steps);

    if ($rolled === []) {
        line('Nothing to roll back.');
    }

    foreach ($rolled as $name) {
        line("Rolled back: $name");
    }

    return 0;
});

command('migrate:reset', 'Revert every migration', function () {
    $rolled = migrator()->reset();

    if ($rolled === []) {
        line('Nothing to roll back.');
    }

    foreach ($rolled as $name) {
        line("Rolled back: $name");
    }

    return 0;
});

command('migrate:fresh', 'Drop ALL tables and re-run every migration [--seed] [--force]', function (array $args) {
    [, $options] = parse_arguments($args);
    $connection = Database::connection();
    $database = (string) $connection->config('database');

    if (!isset($options['force']) && !confirm("Drop every table in [$database] on connection [{$connection->name()}] and migrate from scratch?")) {
        line('Aborted. Pass --force to skip the confirmation.');

        return 1;
    }

    $ran = migrator()->fresh();

    line('Dropped all tables.');

    foreach ($ran as $name) {
        line("Migrated: $name");
    }

    return isset($options['seed']) ? run_seeders() : 0;
});

command('migrate:status', 'Show which migrations have run', function () {
    $rows = [];

    foreach (migrator()->status() as $row) {
        $rows[] = [
            $row['batch'] === null ? 'Pending' : 'Ran',
            $row['migration'],
            $row['batch'] ?? '',
            $row['ran_at'] ?? '',
        ];
    }

    print_table(['Status', 'Migration', 'Batch', 'Ran at'], $rows);

    return 0;
});

command('db:seed', 'Run seeders in database/seeders [--class=Name]', function (array $args) {
    [, $options] = parse_arguments($args);

    return run_seeders(isset($options['class']) ? (string) $options['class'] : null);
});

command('db:show', 'Show the connection and its tables with row counts', function (array $args) {
    [$positional] = parse_arguments($args);
    $name = $positional[0] ?? null;
    $connection = Database::connection($name);

    line('Connection: ' . $connection->name() . ' (' . $connection->driver() . ')');
    line('Database:   ' . $connection->config('database'));
    line();

    $rows = [];

    foreach (Schema::getTables($name) as $table) {
        $rows[] = [$table, Database::table($table, $name)->count()];
    }

    print_table(['Table', 'Rows'], $rows);

    return 0;
});

command('db:table', 'Describe a table [name] [connection]', function (array $args) {
    [$positional] = parse_arguments($args);
    $table = $positional[0] ?? '';
    $name = $positional[1] ?? null;

    if ($table === '' || !Schema::hasTable($table, $name)) {
        error_line($table === '' ? 'Usage: php console.php db:table users' : "Table [$table] does not exist.");

        return 1;
    }

    $rows = [];

    foreach (Schema::getColumns($table, $name) as $column) {
        $rows[] = [
            $column['name'],
            $column['type'],
            $column['nullable'] ? 'yes' : 'no',
            $column['default'] ?? '',
            $column['auto_increment'] ? 'yes' : '',
        ];
    }

    print_table(['Column', 'Type', 'Nullable', 'Default', 'Auto'], $rows);

    return 0;
});

// --------------------------------------------------------------- generators --

command('make:migration', 'Create a migration [name] [--create=table] [--table=table] [--sql]', function (array $args) {
    [$positional, $options] = parse_arguments($args);
    $name = str_snake(trim((string) ($positional[0] ?? '')));

    if ($name === '') {
        error_line('Usage: php console.php make:migration create_posts_table');

        return 1;
    }

    $create = isset($options['create']) ? (string) $options['create'] : null;
    $alter = isset($options['table']) ? (string) $options['table'] : null;

    if ($create === null && $alter === null) {
        if (preg_match('/^create_(.+)_table$/', $name, $m)) {
            $create = $m[1];
        } elseif (preg_match('/_(?:to|from|in|on)_(.+)_table$/', $name, $m)) {
            $alter = $m[1];
        } else {
            $alter = 'table_name';
        }
    }

    $stamp = date('Y_m_d_His');

    if (isset($options['sql'])) {
        $table = $create ?? $alter;

        return write_stub(BASE_PATH . "/database/migrations/{$stamp}_{$name}.sql", <<<SQL
            -- up
            CREATE TABLE IF NOT EXISTS $table (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            );

            -- down
            DROP TABLE IF EXISTS $table;

            SQL) ? 0 : 1;
    }

    if ($create !== null) {
        $stub = <<<PHP
            <?php

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('$create', function (Blueprint \$table) {
                        \$table->id();
                        \$table->timestamps();
                    });
                }

                public function down(): void
                {
                    Schema::dropIfExists('$create');
                }
            };

            PHP;
    } else {
        $stub = <<<PHP
            <?php

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::table('$alter', function (Blueprint \$table) {
                        // \$table->string('column')->nullable();
                    });
                }

                public function down(): void
                {
                    Schema::table('$alter', function (Blueprint \$table) {
                        // \$table->dropColumn('column');
                    });
                }
            };

            PHP;
    }

    return write_stub(BASE_PATH . "/database/migrations/{$stamp}_{$name}.php", $stub) ? 0 : 1;
});

command('make:controller', 'Create a controller [name, e.g. Post or PostController]', function (array $args) {
    [$positional] = parse_arguments($args);
    $name = str_studly(trim((string) ($positional[0] ?? '')));

    if ($name === '') {
        error_line('Usage: php console.php make:controller PostController');

        return 1;
    }

    if (!str_ends_with($name, 'Controller')) {
        $name .= 'Controller';
    }

    $view = str_snake(substr($name, 0, -10));

    return write_stub(BASE_PATH . '/controllers/' . $name . '.php', <<<PHP
        <?php

        class $name
        {
            public function index()
            {
                return view('$view');
            }
        }

        PHP) ? 0 : 1;
});

command('make:model', 'Create a model [name] [-m migration] [-f factory] [-c controller]', function (array $args) {
    global $commands;

    [$positional, $options] = parse_arguments($args);
    $name = str_studly(trim((string) ($positional[0] ?? '')));

    if ($name === '') {
        error_line('Usage: php console.php make:model Post -mf');

        return 1;
    }

    $table = str_plural(str_snake($name));

    $ok = write_stub(BASE_PATH . '/models/' . $name . '.php', <<<PHP
        <?php

        class $name extends Model
        {
            protected static string \$table = '$table';

            // Columns that create()/fill()/update() may set from user input.
            protected static array \$fillable = [];

            // Columns left out of toArray() / JSON output.
            protected static array \$hidden = [];

            // Attribute casts: 'int', 'bool', 'float', 'array', 'datetime', 'date', ...
            protected static array \$casts = [];
        }

        PHP);

    if (isset($options['m']) || isset($options['migration'])) {
        $commands['make:migration']['handler'](["create_{$table}_table"]);
    }

    if (isset($options['f']) || isset($options['factory'])) {
        $commands['make:factory']['handler']([$name]);
    }

    if (isset($options['c']) || isset($options['controller'])) {
        $commands['make:controller']['handler']([$name]);
    }

    return $ok ? 0 : 1;
});

command('make:factory', 'Create a model factory [model name, e.g. Post]', function (array $args) {
    [$positional] = parse_arguments($args);
    $model = str_studly(trim((string) ($positional[0] ?? '')));

    if ($model === '') {
        error_line('Usage: php console.php make:factory Post');

        return 1;
    }

    if (str_ends_with($model, 'Factory')) {
        $model = substr($model, 0, -7);
    }

    return write_stub(BASE_PATH . '/database/factories/' . $model . 'Factory.php', <<<PHP
        <?php

        // $model::factory()->count(5)->create();

        class {$model}Factory extends Factory
        {
            protected string \$model = '$model';

            public function definition(): array
            {
                return [
                    // 'title' => fake()->sentence(),
                ];
            }
        }

        PHP) ? 0 : 1;
});

command('make:middleware', 'Create a middleware file [name, e.g. admin]', function (array $args) {
    [$positional] = parse_arguments($args);
    $name = str_snake(trim((string) ($positional[0] ?? '')));

    if ($name === '') {
        error_line('Usage: php console.php make:middleware admin');

        return 1;
    }

    return write_stub(BASE_PATH . '/middleware/' . $name . '.php', <<<PHP
        <?php

        // Loaded automatically by core/bootstrap.php. Attach it to a route:
        //   get('/admin', 'AdminController@index', 'admin', ['$name']);
        // or to every route with add_global_middleware('$name').

        middleware('$name', function (callable \$next) {
            // Return redirect()/abort() to stop the request here.

            return \$next();
        });

        PHP) ? 0 : 1;
});

command('make:seeder', 'Create a database seeder [name, e.g. UserSeeder]', function (array $args) {
    [$positional] = parse_arguments($args);
    $name = str_studly(trim((string) ($positional[0] ?? '')));

    if ($name === '') {
        error_line('Usage: php console.php make:seeder UserSeeder');

        return 1;
    }

    return write_stub(BASE_PATH . '/database/seeders/' . $name . '.php', <<<PHP
        <?php

        // Run with: php console.php db:seed --class=$name

        return function (): void {
            // User::factory()->count(10)->create();
        };

        PHP) ? 0 : 1;
});

command('test', 'Run the test suite [optional filename filter]', function (array $args) {
    // Database tests want an in-memory SQLite database. If the extension
    // exists but is not loaded, re-run PHP with it enabled.
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true) && getenv('CONSOLE_REEXEC') === false) {
        $extension = PHP_OS_FAMILY === 'Windows' ? 'php_pdo_sqlite.dll' : 'pdo_sqlite.so';
        $directory = (string) (ini_get('extension_dir') ?: PHP_EXTENSION_DIR);

        if (is_file(rtrim($directory, '/\\') . '/' . $extension)) {
            putenv('CONSOLE_REEXEC=1');

            passthru(sprintf(
                '%s -d extension=pdo_sqlite %s test %s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(__FILE__),
                implode(' ', array_map('escapeshellarg', $args))
            ), $code);

            return $code;
        }
    }

    require_once BASE_PATH . '/core/testing.php';

    [$positional] = parse_arguments($args);
    $filter = strtolower((string) ($positional[0] ?? ''));
    $files = array_values(array_filter(
        glob(BASE_PATH . '/tests/*.php') ?: [],
        fn ($file) => $filter === '' || str_contains(strtolower(basename($file)), $filter)
    ));

    if ($files === []) {
        error_line('No test files matched.');

        return 1;
    }

    return run_tests($files);
});

// ------------------------------------------------------------------------

$name = $argv[1] ?? 'help';

if (!isset($commands[$name])) {
    error_line("Unknown command [$name].");
    error_line('');
    $commands['help']['handler']([]);

    exit(1);
}

exit((int) $commands[$name]['handler'](array_slice($argv, 2)));
