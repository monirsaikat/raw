<?php

// Schema builder: Schema::create('posts', fn (Blueprint $t) => ...),
// Schema::table('posts', ...), Schema::dropIfExists('posts'),
// Schema::hasTable('posts'), Schema::hasColumn('posts', 'slug'), ...
// Every method takes an optional connection name as its last argument.

class Schema
{
    public static function connection(?string $name = null): Connection
    {
        return Database::connection($name);
    }

    public static function grammar(?string $connection = null): SchemaGrammar
    {
        $conn = self::connection($connection);
        $options = ['charset' => $conn->config('charset'), 'collation' => $conn->config('collation')];

        return $conn->driver() === 'sqlite' ? new SqliteSchemaGrammar($options) : new SchemaGrammar($options);
    }

    public static function create(string $table, Closure $callback, ?string $connection = null): void
    {
        $blueprint = new Blueprint($table, true);
        $callback($blueprint);

        self::run($blueprint, $connection);
    }

    public static function table(string $table, Closure $callback, ?string $connection = null): void
    {
        $blueprint = new Blueprint($table, false);
        $callback($blueprint);

        self::run($blueprint, $connection);
    }

    public static function drop(string $table, ?string $connection = null): void
    {
        self::connection($connection)->unprepared(self::grammar($connection)->compileDrop($table));
    }

    public static function dropIfExists(string $table, ?string $connection = null): void
    {
        self::connection($connection)->unprepared(self::grammar($connection)->compileDropIfExists($table));
    }

    public static function dropAllTables(?string $connection = null): void
    {
        self::withoutForeignKeyConstraints(function () use ($connection): void {
            foreach (self::getTables($connection) as $table) {
                self::drop($table, $connection);
            }
        }, $connection);
    }

    public static function rename(string $from, string $to, ?string $connection = null): void
    {
        self::connection($connection)->unprepared(self::grammar($connection)->compileRename($from, $to));
    }

    public static function hasTable(string $table, ?string $connection = null): bool
    {
        return self::grammar($connection)->tableExists(self::connection($connection), $table);
    }

    public static function hasColumn(string $table, string $column, ?string $connection = null): bool
    {
        return in_array(strtolower($column), array_map('strtolower', self::getColumnListing($table, $connection)), true);
    }

    public static function hasColumns(string $table, array $columns, ?string $connection = null): bool
    {
        $existing = array_map('strtolower', self::getColumnListing($table, $connection));

        foreach ($columns as $column) {
            if (!in_array(strtolower($column), $existing, true)) {
                return false;
            }
        }

        return true;
    }

    public static function getColumnListing(string $table, ?string $connection = null): array
    {
        return array_column(self::getColumns($table, $connection), 'name');
    }

    // [['name' => ..., 'type' => ..., 'nullable' => bool, 'default' => ..., 'auto_increment' => bool], ...]
    public static function getColumns(string $table, ?string $connection = null): array
    {
        return self::grammar($connection)->columns(self::connection($connection), $table);
    }

    public static function getTables(?string $connection = null): array
    {
        return self::grammar($connection)->tables(self::connection($connection));
    }

    public static function enableForeignKeyConstraints(?string $connection = null): void
    {
        self::grammar($connection)->enableForeignKeyConstraints(self::connection($connection));
    }

    public static function disableForeignKeyConstraints(?string $connection = null): void
    {
        self::grammar($connection)->disableForeignKeyConstraints(self::connection($connection));
    }

    public static function withoutForeignKeyConstraints(callable $callback, ?string $connection = null)
    {
        self::disableForeignKeyConstraints($connection);

        try {
            return $callback();
        } finally {
            self::enableForeignKeyConstraints($connection);
        }
    }

    // The SQL a blueprint would run, without running it.
    public static function toSql(Blueprint $blueprint, ?string $connection = null): array
    {
        return self::grammar($connection)->compile($blueprint);
    }

    private static function run(Blueprint $blueprint, ?string $connection): void
    {
        $conn = self::connection($connection);

        foreach (self::grammar($connection)->compile($blueprint) as $sql) {
            $conn->unprepared($sql);
        }
    }
}
