<?php

class Migrator
{
    public function __construct(private string $path) {}

    public function run(): array
    {
        $this->ensureMigrationsTable();

        $applied = array_column(Database::select('SELECT migration FROM migrations'), 'migration');

        $files = glob($this->path . '/*.sql');
        sort($files);

        $ran = [];

        foreach ($files as $file) {
            $name = basename($file, '.sql');

            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);

            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                Database::statement($statement);
            }

            Database::insert(
                'INSERT INTO migrations (migration, ran_at) VALUES (?, CURRENT_TIMESTAMP)',
                [$name]
            );

            $ran[] = $name;
        }

        return $ran;
    }

    private function ensureMigrationsTable(): void
    {
        Database::statement(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }
}
