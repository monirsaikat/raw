<?php

// SQLite flavour of the schema grammar. Covers what SQLite can do: create
// tables (with inline foreign keys), add/drop/rename columns, indexes.
// Modifying columns and adding constraints to existing tables are not
// supported by SQLite and throw.

class SqliteSchemaGrammar extends SchemaGrammar
{
    public function compileCreate(Blueprint $blueprint): array
    {
        $definitions = [];

        foreach ($blueprint->columns as $column) {
            $definitions[] = $this->columnSql($column);
        }

        $indexes = [];

        foreach ($this->indexCommands($blueprint) as $command) {
            $columns = $this->columnize($command['columns'] ?? []);

            switch ($command['type']) {
                case 'primary':
                    $definitions[] = 'PRIMARY KEY (' . $columns . ')';
                    break;
                case 'unique':
                    $indexes[] = 'CREATE UNIQUE INDEX ' . $this->wrap($command['name']) . ' ON ' . $this->wrap($blueprint->table) . ' (' . $columns . ')';
                    break;
                case 'index':
                    $indexes[] = 'CREATE INDEX ' . $this->wrap($command['name']) . ' ON ' . $this->wrap($blueprint->table) . ' (' . $columns . ')';
                    break;
                case 'foreign':
                    $definitions[] = $this->foreignSql($blueprint, $command['definition']);
                    break;
            }
        }

        $sql = 'CREATE TABLE ' . $this->wrap($blueprint->table) . " (\n    " . implode(",\n    ", $definitions) . "\n)";

        return array_merge([$sql], $indexes);
    }

    public function compileAlter(Blueprint $blueprint): array
    {
        $table = $this->wrap($blueprint->table);
        $statements = [];

        foreach ($blueprint->columns as $column) {
            if ($column->change) {
                throw new RuntimeException('SQLite cannot modify columns; recreate the table instead.');
            }

            $statements[] = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $this->columnSql($column);
        }

        foreach ($this->indexCommands($blueprint) as $command) {
            $columns = $this->columnize($command['columns'] ?? []);

            $statements[] = match ($command['type']) {
                'unique' => 'CREATE UNIQUE INDEX ' . $this->wrap($command['name']) . ' ON ' . $table . ' (' . $columns . ')',
                'index' => 'CREATE INDEX ' . $this->wrap($command['name']) . ' ON ' . $table . ' (' . $columns . ')',
                default => throw new RuntimeException('SQLite cannot add ' . $command['type'] . ' constraints to an existing table.'),
            };
        }

        foreach ($blueprint->commands as $command) {
            $statements = array_merge($statements, $this->compileAlterCommand($blueprint, $command));
        }

        return $statements;
    }

    protected function compileAlterCommand(Blueprint $blueprint, array $command): array
    {
        $table = $this->wrap($blueprint->table);

        return match ($command['type']) {
            'dropColumn' => array_map(fn ($column) => 'ALTER TABLE ' . $table . ' DROP COLUMN ' . $this->wrap($column), $command['columns']),
            'dropIndex', 'dropUnique' => ['DROP INDEX ' . $this->wrap($command['name'])],
            'dropPrimary', 'dropForeign' => throw new RuntimeException('SQLite cannot drop ' . $command['type'] . ' constraints from an existing table.'),
            'renameColumn' => ['ALTER TABLE ' . $table . ' RENAME COLUMN ' . $this->wrap($command['from']) . ' TO ' . $this->wrap($command['to'])],
            'rename' => ['ALTER TABLE ' . $table . ' RENAME TO ' . $this->wrap($command['to'])],
            default => [],
        };
    }

    public function compileRename(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->wrap($from) . ' RENAME TO ' . $this->wrap($to);
    }

    protected function columnType(ColumnDefinition $column): string
    {
        $p = $column->parameters;

        if ($column->autoIncrement) {
            return 'INTEGER';
        }

        return match ($column->type) {
            'integer', 'bigInteger', 'mediumInteger', 'smallInteger', 'tinyInteger', 'year' => 'INTEGER',
            'boolean' => 'TINYINT(1)',
            'string' => 'VARCHAR(' . ($p['length'] ?? 255) . ')',
            'char' => 'CHAR(' . ($p['length'] ?? 255) . ')',
            'text', 'mediumText', 'longText', 'json' => 'TEXT',
            'uuid' => 'CHAR(36)',
            'binary' => 'BLOB',
            'enum' => 'VARCHAR(255) CHECK (' . $this->wrap($column->name) . ' IN (' . implode(', ', array_map(fn ($v) => $this->quoteString((string) $v), $p['allowed'] ?? [])) . '))',
            'ipAddress' => 'VARCHAR(45)',
            'macAddress' => 'VARCHAR(17)',
            'float', 'double' => 'REAL',
            'decimal' => 'NUMERIC(' . ($p['precision'] ?? 8) . ', ' . ($p['scale'] ?? 2) . ')',
            'date' => 'DATE',
            'dateTime', 'timestamp' => 'DATETIME',
            'time' => 'TIME',
            default => throw new InvalidArgumentException("Unknown column type [{$column->type}]."),
        };
    }

    protected function modifiers(ColumnDefinition $column): string
    {
        if ($column->autoIncrement) {
            return ' PRIMARY KEY AUTOINCREMENT NOT NULL';
        }

        $sql = $column->nullable ? ' NULL' : ' NOT NULL';

        if ($column->useCurrent) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->defaultValue($column->default);
        }

        return $sql;
    }

    public function tableExists(Connection $connection, string $table): bool
    {
        return $connection->selectOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) !== null;
    }

    public function tables(Connection $connection): array
    {
        return array_map(
            fn ($row) => $row['name'],
            $connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
        );
    }

    public function columns(Connection $connection, string $table): array
    {
        return array_map(fn ($row) => [
            'name' => $row['name'],
            'type' => strtolower((string) $row['type']),
            'nullable' => !(int) $row['notnull'],
            'default' => $row['dflt_value'],
            'auto_increment' => (int) $row['pk'] === 1 && strtoupper((string) $row['type']) === 'INTEGER',
        ], $connection->select('PRAGMA table_info(' . $this->wrap($table) . ')'));
    }

    public function enableForeignKeyConstraints(Connection $connection): void
    {
        $connection->unprepared('PRAGMA foreign_keys = ON');
    }

    public function disableForeignKeyConstraints(Connection $connection): void
    {
        $connection->unprepared('PRAGMA foreign_keys = OFF');
    }
}
