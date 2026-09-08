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

    line('ComfreePHP console');
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

command('make:policy', 'Create a policy class [name, e.g. PostPolicy] [--model=Post]', function (array $args) {
    [$positional, $options] = parse_arguments($args);
    $name = str_studly(trim((string) ($positional[0] ?? '')));

    if ($name === '') {
        error_line('Usage: php console.php make:policy PostPolicy --model=Post');

        return 1;
    }

    if (!str_ends_with($name, 'Policy')) {
        $name .= 'Policy';
    }

    $model = str_studly((string) ($options['model'] ?? substr($name, 0, -6)));
    $variable = '$' . str_camel($model);

    return write_stub(BASE_PATH . '/policies/' . $name . '.php', <<<PHP
        <?php

        // Answers can('view', $variable), can('update', $variable), authorize('delete', $variable), ...
        // Found automatically for the $model model. Return true to allow, false to deny.

        class $name
        {
            // Runs first for every ability; return true/false to decide, null to continue.
            // public function before(User \$user, string \$ability): ?bool
            // {
            //     return \$user->is_admin ? true : null;
            // }

            public function viewAny(User \$user): bool
            {
                return true;
            }

            public function view(User \$user, $model $variable): bool
            {
                return true;
            }

            public function create(User \$user): bool
            {
                return true;
            }

            public function update(User \$user, $model $variable): bool
            {
                return {$variable}->user_id === \$user->id;
            }

            public function delete(User \$user, $model $variable): bool
            {
                return {$variable}->user_id === \$user->id;
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

// ---------------------------------------------------------------- benchmarks --

command('bench', 'Time the framework core in-process [--iterations=2000] [--json]', function (array $args) {
    [, $options] = parse_arguments($args);
    $n = max(100, (int) ($options['iterations'] ?? 2000));

    require_once BASE_PATH . '/core/testing.php';

    $results = [];

    $measure = function (string $label, callable $operation, int $iterations) use (&$results): void {
        $operation(); // warm caches

        $start = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $operation();
        }

        $nanoseconds = max(1, hrtime(true) - $start);

        $results[] = [
            'benchmark' => $label,
            'iterations' => $iterations,
            'total_ms' => round($nanoseconds / 1e6, 1),
            'per_op_us' => round($nanoseconds / $iterations / 1e3, 1),
            'ops_per_sec' => (int) round($iterations / ($nanoseconds / 1e9)),
        ];
    };

    $skipped = fn (string $label) => ['benchmark' => $label, 'iterations' => 0, 'total_ms' => null, 'per_op_us' => null, 'ops_per_sec' => null];

    $results[] = [
        'benchmark' => 'Bootstrap: env, config, core modules',
        'iterations' => 1,
        'total_ms' => round((APP_BOOTSTRAPPED - APP_START) * 1000, 2),
        'per_op_us' => null,
        'ops_per_sec' => null,
    ];

    // Routing against a table of 400 routes, no middleware.
    routes_reset();
    global_middleware([]);

    for ($i = 0; $i < 200; $i++) {
        get("/static-$i", fn () => 'ok', "static.$i");
        get("/items-$i/{id:\d+}/{slug?}", fn ($id) => $id, "items.$i");
    }

    $measure('Route dispatch: static path (400 routes)', fn () => route_dispatch('GET', '/static-150'), $n);
    $measure('Route dispatch: {id:\d+}/{slug?} path', fn () => route_dispatch('GET', '/items-150/42/hello'), $n);
    $measure('route_url() with parameters', fn () => route_url('items.150', ['id' => 42, 'slug' => 'hello']), $n);

    $measure('Query builder: compile a 5-clause SELECT', fn () => Database::table('posts')
        ->select('id', 'title')->where('published', 1)->whereIn('category_id', [1, 2, 3])
        ->whereNull('deleted_at')->orderByDesc('created_at')->limit(20)->toSql(), $n);

    $measure('Validation: 3 fields, 8 rules', fn () => validate(
        ['name' => 'Ann', 'email' => 'ann@example.com', 'age' => '30'],
        ['name' => 'required|max:100', 'email' => 'required|email', 'age' => 'required|integer|between:18,99']
    ), $n);

    $rows = array_map(fn ($i) => ['id' => $i, 'n' => $i % 7], range(1, 100));
    $measure('Collection: filter/map/sum over 100 rows', fn () => collect($rows)->filter(fn ($r) => $r['n'] > 2)->map(fn ($r) => $r['id'] * 2)->sum(), $n);

    $measure('View: render a standalone template', fn () => view('errors/error', ['status' => 200, 'title' => 'OK', 'message' => 'Hello']), intdiv($n, 10));

    // Whole requests through the in-process client: routing, middleware, session, views.
    http_use_app_routes();
    global_middleware(['csrf']);

    $measure('Full request: GET / (layout, session, CSRF)', fn () => http_get('/'), intdiv($n, 10));
    $measure('Full request: GET /api/ping (JSON)', fn () => http_json('GET', '/api/ping'), intdiv($n, 10));
    $measure('Full request: POST /contact (validation → redirect)', fn () => http_post('/contact', ['name' => '']), intdiv($n, 10));

    try {
        use_test_database();

        $measure('SQLite in memory: insert', fn () => Database::table('messages')->insert(['name' => 'Ann', 'email' => 'a@b.co', 'message' => 'Hi']), intdiv($n, 2));
        $measure('SQLite in memory: find() by id', fn () => Database::table('messages')->find(1), $n);
        $measure('SQLite in memory: Model::find() + toArray()', fn () => Message::find(1)->toArray(), $n);
        $measure('SQLite in memory: 50-row get() hydrated', fn () => Message::limit(50)->get(), intdiv($n, 10));
    } catch (TestSkipped $e) {
        $results[] = $skipped('SQLite: skipped (' . $e->getMessage() . ')');
    }

    try {
        $mysql = Database::connection('mysql');
        $mysql->pdo();

        $measure('MySQL: SELECT 1 round-trip', fn () => $mysql->scalar('SELECT 1'), intdiv($n, 2));
        $measure('MySQL: SELECT * FROM users LIMIT 10', fn () => $mysql->table('users')->limit(10)->get(), intdiv($n, 2));
    } catch (Throwable $e) {
        $results[] = $skipped('MySQL: skipped (not reachable)');
    }

    $opcache = function_exists('opcache_get_status') && @opcache_get_status(false) ? 'on' : 'off';
    $environment = sprintf('PHP %s, %s %s, OPcache %s', PHP_VERSION, php_uname('s'), php_uname('m'), $opcache);

    if (isset($options['json'])) {
        line(json_encode(['environment' => $environment, 'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1), 'results' => $results], JSON_PRETTY_PRINT));

        return 0;
    }

    print_table(['Benchmark', 'Iterations', 'Total', 'Per op', 'Ops/sec'], array_map(fn ($r) => [
        $r['benchmark'],
        $r['iterations'] ?: '',
        $r['total_ms'] === null ? '' : $r['total_ms'] . ' ms',
        $r['per_op_us'] === null ? '' : $r['per_op_us'] . ' us',
        $r['ops_per_sec'] === null ? '' : number_format($r['ops_per_sec']),
    ], $results));

    line();
    line($environment . ', peak memory ' . round(memory_get_peak_usage(true) / 1048576, 1) . ' MB');
    line('In-process numbers exclude PHP start-up and the web server; use bench:http for end-to-end throughput.');

    if (PHP_OS_FAMILY === 'Windows') {
        line('Windows: real-time antivirus scanning adds several milliseconds to every changed file (rate-limit counters,');
        line('cache writes, sessions). Exclude storage/ from scanning on development machines for representative numbers.');
    }

    return 0;
});

function find_apache_bench(): ?string
{
    $lookup = PHP_OS_FAMILY === 'Windows' ? 'where ab 2>NUL' : 'which ab 2>/dev/null';
    $found = strtok(trim((string) shell_exec($lookup)), "\r\n");

    if ($found !== false && $found !== '' && is_file($found)) {
        return $found;
    }

    foreach (['C:\\xampp\\apache\\bin\\ab.exe', '/usr/bin/ab', '/usr/sbin/ab', '/opt/homebrew/bin/ab'] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

// Built-in load client on libcurl's multi interface: keeps `concurrency`
// requests in flight until `requests` have completed and times each one.
// It runs wherever PHP's curl extension does, reuses connections the way a
// browser does, and reports latency with sub-millisecond resolution.
function load_test(string $url, int $requests, int $concurrency): array
{
    $multi = curl_multi_init();
    $handle = function () use ($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: */*'],
        ]);

        return $ch;
    };

    $times = [];
    $failed = 0;
    $non2xx = 0;
    $bytes = 0;
    $started = 0;
    $completed = 0;
    $begin = microtime(true);

    for ($i = 0; $i < min($concurrency, $requests); $i++) {
        curl_multi_add_handle($multi, $handle());
        $started++;
    }

    do {
        curl_multi_exec($multi, $active);

        if ($active) {
            curl_multi_select($multi, 1.0);
        }

        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ($info['result'] !== CURLE_OK) {
                $failed++;
            } elseif ($code < 200 || $code >= 300) {
                $non2xx++;
            }

            $times[] = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
            $bytes = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
            curl_multi_remove_handle($multi, $ch);
            $completed++;

            if ($started < $requests) {
                curl_multi_add_handle($multi, $handle());
                $started++;
                $active = 1;
            }
        }
    } while ($active || $completed < $requests);

    $elapsed = microtime(true) - $begin;
    curl_multi_close($multi);
    sort($times);
    $percentile = fn (int $p) => round($times[(int) floor($p / 100 * (count($times) - 1))], 2);

    return [
        'engine' => 'curl',
        'requests' => $completed,
        'elapsed_s' => round($elapsed, 3),
        'requests_per_second' => round($completed / max($elapsed, 1e-6), 1),
        'mean_ms' => round(array_sum($times) / max(count($times), 1), 2),
        'p50_ms' => $percentile(50),
        'p95_ms' => $percentile(95),
        'p99_ms' => $percentile(99),
        'failed' => $failed,
        'non_2xx' => $non2xx,
        'document_bytes' => $bytes,
    ];
}

// The same measurement through ApacheBench, for cross-checking. ab counts a
// response whose length differs from the first one as "failed"; frameworks
// that embed random-length tokens trigger that on every request, so those
// are subtracted and `failed` holds connect/receive errors and exceptions.
function apache_bench_run(string $ab, string $url, int $requests, int $concurrency): array
{
    $output = (string) shell_exec(sprintf('%s -n %d -c %d -q %s 2>&1', escapeshellarg($ab), $requests, $concurrency, escapeshellarg($url)));
    $number = fn (string $pattern) => preg_match($pattern, $output, $m) ? (float) $m[1] : null;
    $failed = (int) $number('/Failed requests:\s+(\d+)/');
    $lengthMismatches = (int) $number('/\(Connect: \d+, Receive: \d+, Length: (\d+), Exceptions: \d+\)/');

    return [
        'engine' => 'ab',
        'requests' => (int) $number('/Complete requests:\s+(\d+)/'),
        'elapsed_s' => $number('/Time taken for tests:\s+([\d.]+)/'),
        'requests_per_second' => $number('/Requests per second:\s+([\d.]+)/'),
        'mean_ms' => $number('/Time per request:\s+([\d.]+) \[ms\] \(mean\)/'),
        'p50_ms' => $number('/\s+50%\s+(\d+)/'),
        'p95_ms' => $number('/\s+95%\s+(\d+)/'),
        'p99_ms' => $number('/\s+99%\s+(\d+)/'),
        'failed' => max(0, $failed - $lengthMismatches),
        'non_2xx' => (int) $number('/Non-2xx responses:\s+(\d+)/'),
        'document_bytes' => (int) $number('/Document Length:\s+(\d+)/'),
        'raw' => $output,
    ];
}

// Picks the load client for bench:http and bench:compare: the built-in curl
// client, or ApacheBench when --ab is passed. Returns null after printing
// why neither is available.
function load_client(array $options): ?callable
{
    if (isset($options['ab'])) {
        $ab = find_apache_bench();

        if ($ab === null) {
            error_line('ApacheBench (ab) was not found. It ships with Apache (XAMPP: apache/bin/ab.exe, Debian: apache2-utils).');

            return null;
        }

        return fn (string $url, int $requests, int $concurrency) => apache_bench_run($ab, $url, $requests, $concurrency);
    }

    if (!function_exists('curl_multi_init')) {
        error_line('The curl extension is not loaded. Enable it, or pass --ab to use ApacheBench.');

        return null;
    }

    return 'load_test';
}

command('bench:http', 'Load-test a URL [url] [--requests=500] [--concurrency=10] [--ab] [--raw]', function (array $args) {
    [$positional, $options] = parse_arguments($args);
    $url = (string) ($positional[0] ?? '');

    if ($url === '') {
        error_line('Usage: php console.php bench:http http://localhost/myapp/ --requests=500 --concurrency=10');

        return 1;
    }

    $requests = max(1, (int) ($options['requests'] ?? 500));
    $concurrency = max(1, min($requests, (int) ($options['concurrency'] ?? 10)));
    $client = load_client($options);

    if ($client === null) {
        return 1;
    }

    line("Sending $requests requests, $concurrency at a time, to $url" . (isset($options['ab']) ? ' (ApacheBench)' : ''));

    $result = $client($url, $requests, $concurrency);
    $ms = fn ($value) => $value === null ? 'n/a' : $value . ' ms';

    print_table(['Metric', 'Value'], [
        ['Requests per second', ($result['requests_per_second'] ?? 'n/a') . ' req/s'],
        ['Time per request (mean)', $ms($result['mean_ms'])],
        ['50% of requests within', $ms($result['p50_ms'])],
        ['95% of requests within', $ms($result['p95_ms'])],
        ['99% of requests within', $ms($result['p99_ms'])],
        ['Failed requests', $result['failed']],
        ['Non-2xx responses', $result['non_2xx']],
        ['Document length', $result['document_bytes'] . ' bytes'],
        ['Completed', $result['requests'] . ' requests in ' . $result['elapsed_s'] . ' s'],
    ]);

    if (isset($options['raw'], $result['raw'])) {
        line();
        line($result['raw']);
    }

    return ($result['requests'] ?? 0) > 0 ? 0 : 1;
});

// Load-tests several targets in alternating rounds so that background noise
// hits every target equally, then reports the median of the runs.
command('bench:compare', 'Compare targets under load [name=url ...] [--requests=1000] [--concurrency=10] [--runs=3] [--ab] [--json]', function (array $args) {
    [$positional, $options] = parse_arguments($args);
    $targets = [];

    foreach ($positional as $argument) {
        [$name, $url] = array_pad(explode('=', $argument, 2), 2, null);

        if ($url === null) {
            $url = $name;
            $name = (string) parse_url($url, PHP_URL_HOST) . (string) parse_url($url, PHP_URL_PATH);
        }

        $targets[$name] = $url;
    }

    if ($targets === []) {
        error_line('Usage: php console.php bench:compare comfree=http://localhost/app/ laravel=http://localhost/laravel/public/ --requests=1000 --concurrency=10 --runs=3');

        return 1;
    }

    $requests = max(1, (int) ($options['requests'] ?? 1000));
    $concurrency = max(1, min($requests, (int) ($options['concurrency'] ?? 10)));
    $runs = max(1, (int) ($options['runs'] ?? 3));
    $quiet = isset($options['json']);
    $client = load_client($options);

    if ($client === null) {
        return 1;
    }

    if (!$quiet) {
        line('Warming up ' . count($targets) . ' target(s)...');
    }

    // A short unmeasured burst per target fills OPcache and template caches
    // so the first measured run is not a cold start.
    foreach ($targets as $url) {
        $client($url, 50, 5);
    }

    $samples = [];

    for ($run = 1; $run <= $runs; $run++) {
        foreach ($targets as $name => $url) {
            if (!$quiet) {
                line("Run $run/$runs: $name ($url)");
            }

            $result = $client($url, $requests, $concurrency);

            foreach (['requests_per_second', 'p50_ms', 'p95_ms', 'p99_ms'] as $metric) {
                $samples[$name][$metric][] = $result[$metric];
            }

            $samples[$name]['failed'] = ($samples[$name]['failed'] ?? 0) + $result['failed'] + $result['non_2xx'];
            $samples[$name]['bytes'] = $result['document_bytes'];
        }
    }

    $median = function (array $values): ?float {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));

        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    };

    $rows = [];

    foreach ($samples as $name => $sample) {
        $rows[] = [
            'target' => $name,
            'url' => $targets[$name],
            'requests_per_second' => $median($sample['requests_per_second']),
            'p50_ms' => $median($sample['p50_ms']),
            'p95_ms' => $median($sample['p95_ms']),
            'p99_ms' => $median($sample['p99_ms']),
            'failed' => $sample['failed'],
            'document_bytes' => $sample['bytes'],
        ];
    }

    usort($rows, fn ($a, $b) => ($b['requests_per_second'] ?? 0) <=> ($a['requests_per_second'] ?? 0));
    $fastest = $rows[0]['requests_per_second'] ?? null;

    foreach ($rows as &$row) {
        $row['relative'] = $fastest ? round(($row['requests_per_second'] ?? 0) / $fastest * 100) : null;
    }

    unset($row);

    if ($quiet) {
        line(json_encode([
            'engine' => isset($options['ab']) ? 'ab' : 'curl',
            'requests' => $requests,
            'concurrency' => $concurrency,
            'runs' => $runs,
            'results' => $rows,
        ], JSON_PRETTY_PRINT));

        return 0;
    }

    $ms = fn ($value) => $value === null ? 'n/a' : $value . ' ms';

    line();
    print_table(['Target', 'Req/s (median)', 'p50', 'p95', 'p99', 'Failed', 'Bytes', 'Relative'], array_map(fn ($r) => [
        $r['target'],
        $r['requests_per_second'] === null ? 'n/a' : number_format($r['requests_per_second'], 1),
        $ms($r['p50_ms']),
        $ms($r['p95_ms']),
        $ms($r['p99_ms']),
        $r['failed'],
        $r['document_bytes'],
        $r['relative'] === null ? '' : $r['relative'] . '%',
    ], $rows));
    line();
    line("$requests requests, $concurrency concurrent, median of $runs runs per target, targets alternated between runs" . (isset($options['ab']) ? ', measured with ApacheBench.' : ', measured with the built-in curl client.'));

    return 0;
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

// Additional command files: console/*.php, each calling command() one or
// more times. Framework modules (queue, mail, ...) register theirs here.
foreach (glob(BASE_PATH . '/console/*.php') ?: [] as $commandFile) {
    require_once $commandFile;
}

// ------------------------------------------------------------------------

$name = $argv[1] ?? 'help';

if (!isset($commands[$name])) {
    error_line("Unknown command [$name].");
    error_line('');
    $commands['help']['handler']([]);

    exit(1);
}

exit((int) $commands[$name]['handler'](array_slice($argv, 2)));
