<?php

// Static entry point to the database. Database::table('users') and the raw
// helpers use the default connection from config/database.php;
// Database::connection('name') returns any configured Connection.
// Everyday queries should go through the query builder — the raw helpers
// take bound parameters only; never interpolate request data into SQL.

class Database
{
    private static array $connections = [];
    private static array $runtimeConfigs = [];

    public static function defaultName(): string
    {
        return (string) config('database.default', 'mysql');
    }

    public static function connection(?string $name = null): Connection
    {
        $name ??= self::defaultName();

        return self::$connections[$name] ??= new Connection($name, self::configFor($name));
    }

    public static function configFor(string $name): array
    {
        if (isset(self::$runtimeConfigs[$name])) {
            return self::$runtimeConfigs[$name];
        }

        $config = config('database.connections.' . $name);

        if (is_array($config)) {
            return $config;
        }

        // Legacy flat config/database.php with host/database/... at the top level.
        if ($name === self::defaultName() && config('database.host') !== null) {
            return (array) config('database');
        }

        throw new InvalidArgumentException("Database connection [$name] is not configured.");
    }

    // Registers (or replaces) a connection at runtime, e.g. an in-memory
    // SQLite database for tests.
    public static function addConnection(string $name, array $config): void
    {
        self::$runtimeConfigs[$name] = $config;

        unset(self::$connections[$name]);
    }

    public static function connections(): array
    {
        return self::$connections;
    }

    public static function disconnect(?string $name = null): void
    {
        self::connection($name)->disconnect();
    }

    // Closes and forgets one connection (or all of them).
    public static function purge(?string $name = null): void
    {
        if ($name === null) {
            foreach (self::$connections as $connection) {
                $connection->disconnect();
            }

            self::$connections = [];

            return;
        }

        if (isset(self::$connections[$name])) {
            self::$connections[$name]->disconnect();
            unset(self::$connections[$name]);
        }
    }

    public static function pdo(?string $name = null): PDO
    {
        return self::connection($name)->pdo();
    }

    public static function driver(?string $name = null): string
    {
        return self::connection($name)->driver();
    }

    public static function table(string $table, ?string $connection = null): QueryBuilder
    {
        return self::connection($connection)->table($table);
    }

    public static function query(?string $connection = null): QueryBuilder
    {
        return self::connection($connection)->query();
    }

    public static function raw(string $sql): Raw
    {
        return new Raw($sql);
    }

    // --------------------------------------- default-connection forwarders --

    public static function select(string $sql, array $bindings = []): array
    {
        return self::connection()->select($sql, $bindings);
    }

    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        return self::connection()->selectOne($sql, $bindings);
    }

    public static function scalar(string $sql, array $bindings = [])
    {
        return self::connection()->scalar($sql, $bindings);
    }

    public static function cursor(string $sql, array $bindings = []): Generator
    {
        return self::connection()->cursor($sql, $bindings);
    }

    public static function insert(string $sql, array $bindings = []): string
    {
        return self::connection()->insert($sql, $bindings);
    }

    public static function update(string $sql, array $bindings = []): int
    {
        return self::connection()->update($sql, $bindings);
    }

    public static function delete(string $sql, array $bindings = []): int
    {
        return self::connection()->delete($sql, $bindings);
    }

    public static function statement(string $sql, array $bindings = []): int
    {
        return self::connection()->statement($sql, $bindings);
    }

    public static function unprepared(string $sql): int
    {
        return self::connection()->unprepared($sql);
    }

    public static function transaction(callable $callback, int $attempts = 1)
    {
        return self::connection()->transaction($callback, $attempts);
    }

    public static function beginTransaction(): void
    {
        self::connection()->beginTransaction();
    }

    public static function commit(): void
    {
        self::connection()->commit();
    }

    public static function rollBack(): void
    {
        self::connection()->rollBack();
    }

    public static function transactionLevel(): int
    {
        return self::connection()->transactionLevel();
    }

    public static function lastInsertId(?string $sequence = null): string
    {
        return self::connection()->lastInsertId($sequence);
    }

    public static function listen(callable $listener): void
    {
        self::connection()->listen($listener);
    }

    public static function enableQueryLog(bool $enabled = true): void
    {
        self::connection()->enableQueryLog($enabled);
    }

    // Query log across every opened connection (shown on the debug page).
    public static function queryLog(): array
    {
        $log = [];

        foreach (self::$connections as $connection) {
            $log = array_merge($log, $connection->queryLog());
        }

        return $log;
    }

    public static function flushQueryLog(): void
    {
        foreach (self::$connections as $connection) {
            $connection->flushQueryLog();
        }
    }

    public static function __callStatic(string $method, array $arguments)
    {
        return self::connection()->$method(...$arguments);
    }
}
