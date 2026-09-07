<?php

// Turns a Blueprint into MySQL/MariaDB DDL and answers schema questions
// (table exists, column listing). SqliteSchemaGrammar overrides the
// driver-specific parts.

class SchemaGrammar
{
    public function __construct(protected array $options = [])
    {
    }

    public function compile(Blueprint $blueprint): array
    {
        return $blueprint->creating ? $this->compileCreate($blueprint) : $this->compileAlter($blueprint);
    }

    // --------------------------------------------------------------- create --

    public function compileCreate(Blueprint $blueprint): array
    {
        $definitions = [];

        foreach ($blueprint->columns as $column) {
            $definitions[] = $this->columnSql($column);
        }

        foreach ($this->inlineIndexes($blueprint) as $index) {
            $definitions[] = $index;
        }

        $sql = 'CREATE TABLE ' . $this->wrap($blueprint->table) . " (\n    " . implode(",\n    ", $definitions) . "\n)";

        $sql .= ' ENGINE=' . ($blueprint->engine ?? 'InnoDB');
        $sql .= ' DEFAULT CHARSET=' . ($blueprint->charset ?? $this->options['charset'] ?? 'utf8mb4');
        $sql .= ' COLLATE=' . ($blueprint->collation ?? $this->options['collation'] ?? 'utf8mb4_unicode_ci');

        return [$sql];
    }

    // PRIMARY KEY / UNIQUE / INDEX / FOREIGN KEY lines inside CREATE TABLE.
    protected function inlineIndexes(Blueprint $blueprint): array
    {
        $lines = [];

        foreach ($this->indexCommands($blueprint) as $command) {
            $columns = $this->columnize($command['columns'] ?? []);

            switch ($command['type']) {
                case 'primary':
                    $lines[] = 'PRIMARY KEY (' . $columns . ')';
                    break;
                case 'unique':
                    $lines[] = 'UNIQUE ' . $this->wrap($command['name']) . ' (' . $columns . ')';
                    break;
                case 'index':
                    $lines[] = 'INDEX ' . $this->wrap($command['name']) . ' (' . $columns . ')';
                    break;
                case 'foreign':
                    $lines[] = $this->foreignSql($blueprint, $command['definition']);
                    break;
            }
        }

        return $lines;
    }

    // Index commands plus the ones implied by column modifiers, with names.
    protected function indexCommands(Blueprint $blueprint): array
    {
        $commands = [];

        foreach ($blueprint->columns as $column) {
            if ($column->primary && !$column->autoIncrement) {
                $commands[] = ['type' => 'primary', 'columns' => [$column->name], 'name' => null];
            }

            if ($column->unique !== false) {
                $commands[] = ['type' => 'unique', 'columns' => [$column->name], 'name' => is_string($column->unique) ? $column->unique : null];
            }

            if ($column->index !== false) {
                $commands[] = ['type' => 'index', 'columns' => [$column->name], 'name' => is_string($column->index) ? $column->index : null];
            }
        }

        foreach ($blueprint->commands as $command) {
            if (in_array($command['type'], ['primary', 'unique', 'index', 'foreign'], true)) {
                $commands[] = $command;
            }
        }

        foreach ($commands as &$command) {
            if ($command['type'] !== 'foreign' && empty($command['name'])) {
                $command['name'] = $blueprint->indexName($command['type'], $command['columns']);
            }
        }

        return $commands;
    }

    protected function foreignSql(Blueprint $blueprint, ForeignKeyDefinition $foreign): string
    {
        $name = $foreign->name ?? $blueprint->indexName('foreign', $foreign->columns);

        $sql = 'CONSTRAINT ' . $this->wrap($name) . ' FOREIGN KEY (' . $this->columnize($foreign->columns) . ')'
            . ' REFERENCES ' . $this->wrap((string) $foreign->on) . ' (' . $this->columnize($foreign->references) . ')';

        if ($foreign->onDelete !== null) {
            $sql .= ' ON DELETE ' . $foreign->onDelete;
        }

        if ($foreign->onUpdate !== null) {
            $sql .= ' ON UPDATE ' . $foreign->onUpdate;
        }

        return $sql;
    }

    // ---------------------------------------------------------------- alter --

    public function compileAlter(Blueprint $blueprint): array
    {
        $table = $this->wrap($blueprint->table);
        $statements = [];

        foreach ($blueprint->columns as $column) {
            $statements[] = 'ALTER TABLE ' . $table . ($column->change ? ' MODIFY ' : ' ADD ') . $this->columnSql($column);
        }

        foreach ($this->indexCommands($blueprint) as $command) {
            // Column-level modifiers on a changed column were already part of
            // the column, but new index commands need their own statements.
            $columns = $this->columnize($command['columns'] ?? []);

            $statements[] = match ($command['type']) {
                'primary' => 'ALTER TABLE ' . $table . ' ADD PRIMARY KEY (' . $columns . ')',
                'unique' => 'ALTER TABLE ' . $table . ' ADD UNIQUE ' . $this->wrap($command['name']) . ' (' . $columns . ')',
                'index' => 'ALTER TABLE ' . $table . ' ADD INDEX ' . $this->wrap($command['name']) . ' (' . $columns . ')',
                'foreign' => 'ALTER TABLE ' . $table . ' ADD ' . $this->foreignSql($blueprint, $command['definition']),
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
            'dropIndex', 'dropUnique' => ['ALTER TABLE ' . $table . ' DROP INDEX ' . $this->wrap($command['name'])],
            'dropPrimary' => ['ALTER TABLE ' . $table . ' DROP PRIMARY KEY'],
            'dropForeign' => ['ALTER TABLE ' . $table . ' DROP FOREIGN KEY ' . $this->wrap($command['name'])],
            'renameColumn' => ['ALTER TABLE ' . $table . ' RENAME COLUMN ' . $this->wrap($command['from']) . ' TO ' . $this->wrap($command['to'])],
            'rename' => ['ALTER TABLE ' . $table . ' RENAME TO ' . $this->wrap($command['to'])],
            default => [],
        };
    }

    public function compileDrop(string $table): string
    {
        return 'DROP TABLE ' . $this->wrap($table);
    }

    public function compileDropIfExists(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->wrap($table);
    }

    public function compileRename(string $from, string $to): string
    {
        return 'RENAME TABLE ' . $this->wrap($from) . ' TO ' . $this->wrap($to);
    }

    // -------------------------------------------------------------- columns --

    public function columnSql(ColumnDefinition $column): string
    {
        return $this->wrap($column->name) . ' ' . $this->columnType($column) . $this->modifiers($column);
    }

    protected function columnType(ColumnDefinition $column): string
    {
        $p = $column->parameters;

        return match ($column->type) {
            'integer' => 'INT',
            'bigInteger' => 'BIGINT',
            'mediumInteger' => 'MEDIUMINT',
            'smallInteger' => 'SMALLINT',
            'tinyInteger' => 'TINYINT',
            'boolean' => 'TINYINT(1)',
            'string' => 'VARCHAR(' . ($p['length'] ?? 255) . ')',
            'char' => 'CHAR(' . ($p['length'] ?? 255) . ')',
            'text' => 'TEXT',
            'mediumText' => 'MEDIUMTEXT',
            'longText' => 'LONGTEXT',
            'json' => 'JSON',
            'uuid' => 'CHAR(36)',
            'binary' => 'BLOB',
            'enum' => 'ENUM(' . implode(', ', array_map(fn ($v) => $this->quoteString((string) $v), $p['allowed'] ?? [])) . ')',
            'ipAddress' => 'VARCHAR(45)',
            'macAddress' => 'VARCHAR(17)',
            'float' => 'FLOAT',
            'double' => 'DOUBLE',
            'decimal' => 'DECIMAL(' . ($p['precision'] ?? 8) . ', ' . ($p['scale'] ?? 2) . ')',
            'date' => 'DATE',
            'dateTime' => 'DATETIME' . (($p['precision'] ?? 0) > 0 ? '(' . $p['precision'] . ')' : ''),
            'timestamp' => 'TIMESTAMP' . (($p['precision'] ?? 0) > 0 ? '(' . $p['precision'] . ')' : ''),
            'time' => 'TIME',
            'year' => 'YEAR',
            default => throw new InvalidArgumentException("Unknown column type [{$column->type}]."),
        };
    }

    protected function modifiers(ColumnDefinition $column): string
    {
        $sql = '';

        if ($column->unsigned && in_array($column->type, ['integer', 'bigInteger', 'mediumInteger', 'smallInteger', 'tinyInteger', 'decimal', 'float', 'double'], true)) {
            $sql .= ' UNSIGNED';
        }

        if ($column->charset !== null) {
            $sql .= ' CHARACTER SET ' . $column->charset;
        }

        if ($column->collation !== null) {
            $sql .= ' COLLATE ' . $column->collation;
        }

        $sql .= $column->nullable ? ' NULL' : ' NOT NULL';

        if ($column->useCurrent) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->defaultValue($column->default);
        }

        if ($column->useCurrentOnUpdate) {
            $sql .= ' ON UPDATE CURRENT_TIMESTAMP';
        }

        if ($column->autoIncrement) {
            $sql .= ' AUTO_INCREMENT PRIMARY KEY';
        }

        if ($column->comment !== null) {
            $sql .= ' COMMENT ' . $this->quoteString($column->comment);
        }

        if ($column->first) {
            $sql .= ' FIRST';
        } elseif ($column->after !== null) {
            $sql .= ' AFTER ' . $this->wrap($column->after);
        }

        return $sql;
    }

    protected function defaultValue($value): string
    {
        return match (true) {
            $value === null => 'NULL',
            $value instanceof Raw => $value->sql,
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => $this->quoteString((string) $value),
        };
    }

    // ----------------------------------------------------------- questions --

    public function tableExists(Connection $connection, string $table): bool
    {
        return $connection->selectOne(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            [$table]
        ) !== null;
    }

    public function tables(Connection $connection): array
    {
        return array_map(
            fn ($row) => reset($row),
            $connection->select('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name')
        );
    }

    // [['name', 'type', 'nullable', 'default', 'auto_increment'], ...]
    public function columns(Connection $connection, string $table): array
    {
        $rows = $connection->select(
            'SELECT column_name, column_type, is_nullable, column_default, extra
             FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
            [$table]
        );

        return array_map(fn ($row) => [
            'name' => $row['column_name'] ?? $row['COLUMN_NAME'],
            'type' => $row['column_type'] ?? $row['COLUMN_TYPE'],
            'nullable' => strtoupper($row['is_nullable'] ?? $row['IS_NULLABLE']) === 'YES',
            'default' => $row['column_default'] ?? $row['COLUMN_DEFAULT'] ?? null,
            'auto_increment' => str_contains(strtolower($row['extra'] ?? $row['EXTRA'] ?? ''), 'auto_increment'),
        ], $rows);
    }

    public function enableForeignKeyConstraints(Connection $connection): void
    {
        $connection->unprepared('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function disableForeignKeyConstraints(Connection $connection): void
    {
        $connection->unprepared('SET FOREIGN_KEY_CHECKS = 0');
    }

    // -------------------------------------------------------------- helpers --

    public function wrap(string $identifier): string
    {
        return QueryBuilder::wrap($identifier);
    }

    protected function columnize(array $columns): string
    {
        return implode(', ', array_map([$this, 'wrap'], $columns));
    }

    protected function quoteString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}
