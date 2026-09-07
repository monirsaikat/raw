<?php

// Runs database/migrations in filename order. Two formats are supported:
//   *.php  — returns a Migration with up()/down() using the Schema builder
//   *.sql  — plain SQL with `-- up` and optional `-- down` sections
// Applied migrations are tracked in the `migrations` table in batches so
// rollback() can undo the last batch and reset() everything.

class Migrator
{
    public function __construct(private string $path, private ?string $connection = null)
    {
    }

    public function run(): array
    {
        $this->ensureTable();

        $pending = $this->pending();

        if ($pending === []) {
            return [];
        }

        $batch = (int) $this->connection()->scalar('SELECT COALESCE(MAX(batch), 0) FROM migrations') + 1;
        $ran = [];

        foreach ($pending as $name => $file) {
            $this->runUp($file);

            $this->connection()->insert(
                'INSERT INTO migrations (migration, batch, ran_at) VALUES (?, ?, ?)',
                [$name, $batch, date('Y-m-d H:i:s')]
            );

            $ran[] = $name;
        }

        return $ran;
    }

    // Reverts the last $steps batches, newest migration first.
    public function rollback(int $steps = 1): array
    {
        $this->ensureTable();

        $rolled = [];

        for ($i = 0; $i < $steps; $i++) {
            $batch = $this->connection()->scalar('SELECT MAX(batch) FROM migrations');

            if ($batch === null) {
                break;
            }

            $rows = $this->connection()->select('SELECT migration FROM migrations WHERE batch = ? ORDER BY id DESC', [$batch]);

            foreach ($rows as $row) {
                $name = $row['migration'];
                $file = $this->files()[$name] ?? null;

                if ($file === null) {
                    throw new RuntimeException("Migration file for [$name] is missing; cannot roll back.");
                }

                $this->runDown($file);

                $this->connection()->delete('DELETE FROM migrations WHERE migration = ?', [$name]);

                $rolled[] = $name;
            }
        }

        return $rolled;
    }

    // Rolls back every batch.
    public function reset(): array
    {
        $this->ensureTable();

        $batches = (int) $this->connection()->scalar('SELECT COUNT(DISTINCT batch) FROM migrations');

        return $batches === 0 ? [] : $this->rollback($batches);
    }

    // Drops every table and runs all migrations from scratch.
    public function fresh(): array
    {
        Schema::dropAllTables($this->connection);

        return $this->run();
    }

    // [['migration' => name, 'batch' => int|null, 'ran_at' => string|null], ...]
    public function status(): array
    {
        $this->ensureTable();

        $applied = $this->applied();
        $status = [];

        foreach ($this->files() as $name => $file) {
            $status[] = [
                'migration' => $name,
                'batch' => isset($applied[$name]) ? (int) $applied[$name]['batch'] : null,
                'ran_at' => $applied[$name]['ran_at'] ?? null,
            ];
        }

        return $status;
    }

    public function pending(): array
    {
        $this->ensureTable();

        return array_diff_key($this->files(), $this->applied());
    }

    // name => path, sorted by name (timestamps first).
    public function files(): array
    {
        $files = [];

        foreach (array_merge(glob($this->path . '/*.php') ?: [], glob($this->path . '/*.sql') ?: []) as $file) {
            $files[pathinfo($file, PATHINFO_FILENAME)] = $file;
        }

        ksort($files);

        return $files;
    }

    private function applied(): array
    {
        $rows = $this->connection()->select('SELECT migration, batch, ran_at FROM migrations');

        return array_column($rows, null, 'migration');
    }

    private function connection(): Connection
    {
        return Database::connection($this->connection);
    }

    private function runUp(string $file): void
    {
        if (str_ends_with($file, '.php')) {
            $this->runPhp($file, 'up');

            return;
        }

        $this->execute($this->sections($file)['up']);
    }

    private function runDown(string $file): void
    {
        if (str_ends_with($file, '.php')) {
            $this->runPhp($file, 'down');

            return;
        }

        $down = $this->sections($file)['down'];

        if (self::statements($down) === []) {
            throw new RuntimeException('Migration [' . basename($file) . "] has no '-- down' section, cannot roll back.");
        }

        $this->execute($down);
    }

    // Runs up()/down() with the migration's (or the migrator's) connection
    // as the default so Schema:: and models inside it target the right one.
    private function runPhp(string $file, string $method): void
    {
        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new RuntimeException('Migration [' . basename($file) . '] must return an instance of Migration.');
        }

        $connection = $migration->getConnection() ?? $this->connection;
        $previous = config('database.default');

        if ($connection !== null) {
            config_set('database.default', $connection);
        }

        try {
            $migration->$method();
        } finally {
            config_set('database.default', $previous);
        }
    }

    private function execute(string $sql): void
    {
        foreach (self::statements($sql) as $statement) {
            $this->connection()->unprepared($statement);
        }
    }

    public function sections(string $file): array
    {
        $contents = (string) file_get_contents($file);

        if (preg_match('/^[ \t]*--[ \t]*down\b.*$/mi', $contents, $m, PREG_OFFSET_CAPTURE)) {
            $up = substr($contents, 0, $m[0][1]);
            $down = substr($contents, $m[0][1]);
        } else {
            $up = $contents;
            $down = '';
        }

        return ['up' => $up, 'down' => $down];
    }

    // Splits SQL into statements, dropping comments. Semicolons inside string
    // literals are not supported — keep migration SQL simple.
    public static function statements(string $sql): array
    {
        $sql = preg_replace('/^[ \t]*--.*$/m', '', $sql);
        $sql = preg_replace('#/\*.*?\*/#s', '', $sql);

        return array_values(array_filter(array_map('trim', explode(';', $sql)), fn ($s) => $s !== ''));
    }

    private function ensureTable(): void
    {
        if (!Schema::hasTable('migrations', $this->connection)) {
            Schema::create('migrations', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('migration');
                $table->unsignedInteger('batch')->default(1);
                $table->dateTime('ran_at')->useCurrent();
            }, $this->connection);

            return;
        }

        // Installs created before batches existed.
        if (!Schema::hasColumn('migrations', 'batch', $this->connection)) {
            Schema::table('migrations', function (Blueprint $table): void {
                $table->unsignedInteger('batch')->default(1)->after('migration');
            }, $this->connection);
        }
    }
}
