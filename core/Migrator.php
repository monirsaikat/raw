<?php

// Runs database/migrations/*.sql in filename order. Each file has an `-- up`
// section and an optional `-- down` section; rollback replays the latter
// for the most recent batch. Applied migrations are tracked in `migrations`.

class Migrator
{
    public function __construct(private string $path)
    {
    }

    public function run(): array
    {
        $this->ensureTable();

        $pending = $this->pending();

        if ($pending === []) {
            return [];
        }

        $batch = (int) Database::scalar('SELECT COALESCE(MAX(batch), 0) FROM migrations') + 1;
        $ran = [];

        foreach ($pending as $name => $file) {
            $this->execute($this->sections($file)['up']);

            Database::insert(
                'INSERT INTO migrations (migration, batch, ran_at) VALUES (?, ?, CURRENT_TIMESTAMP)',
                [$name, $batch]
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
            $batch = Database::scalar('SELECT MAX(batch) FROM migrations');

            if ($batch === null) {
                break;
            }

            $rows = Database::select('SELECT migration FROM migrations WHERE batch = ? ORDER BY id DESC', [$batch]);

            foreach ($rows as $row) {
                $name = $row['migration'];
                $file = $this->path . '/' . $name . '.sql';
                $down = is_file($file) ? $this->sections($file)['down'] : '';

                if (self::statements($down) === []) {
                    throw new RuntimeException("Migration [$name] has no '-- down' section, cannot roll back.");
                }

                $this->execute($down);

                Database::delete('DELETE FROM migrations WHERE migration = ?', [$name]);

                $rolled[] = $name;
            }
        }

        return $rolled;
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
        return array_diff_key($this->files(), $this->applied());
    }

    // name => path, sorted by name (timestamps first).
    public function files(): array
    {
        $files = [];

        foreach (glob($this->path . '/*.sql') ?: [] as $file) {
            $files[basename($file, '.sql')] = $file;
        }

        ksort($files);

        return $files;
    }

    private function applied(): array
    {
        $rows = Database::select('SELECT migration, batch, ran_at FROM migrations');

        return array_column($rows, null, 'migration');
    }

    private function execute(string $sql): void
    {
        foreach (self::statements($sql) as $statement) {
            Database::statement($statement);
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
        Database::statement(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INT UNSIGNED NOT NULL DEFAULT 1,
                ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        // Installs created before batches existed.
        $columns = array_column(Database::select('SHOW COLUMNS FROM migrations'), 'Field');

        if (!in_array('batch', $columns, true)) {
            Database::statement('ALTER TABLE migrations ADD COLUMN batch INT UNSIGNED NOT NULL DEFAULT 1 AFTER migration');
        }
    }
}
