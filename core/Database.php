<?php

// PDO connection (lazy, one per request) plus thin query helpers. Prefer
// Database::table('users')->where(...) — the query builder — for everyday
// queries; the raw helpers take bound parameters only. Never interpolate
// request data into SQL strings.

class Database
{
    private static ?PDO $connection = null;
    private static ?bool $logging = null;
    private static array $queryLog = [];

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $config = (array) config('database', []);

            $dsn = sprintf(
                '%s:host=%s;port=%s;dbname=%s;charset=%s',
                $config['driver'] ?? 'mysql',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? '3306',
                $config['database'] ?? '',
                $config['charset'] ?? 'utf8mb4'
            );

            $options = ($config['options'] ?? []) + [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            try {
                self::$connection = new PDO(
                    $dsn,
                    (string) ($config['username'] ?? ''),
                    (string) ($config['password'] ?? ''),
                    $options
                );
            } catch (PDOException $e) {
                // Rethrown without the original: its stack trace carries the
                // constructor arguments, i.e. the password.
                throw new RuntimeException('Database connection failed: ' . $e->getMessage());
            }
        }

        return self::$connection;
    }

    public static function disconnect(): void
    {
        self::$connection = null;
    }

    public static function table(string $table): QueryBuilder
    {
        return new QueryBuilder($table);
    }

    public static function raw(string $sql): Raw
    {
        return new Raw($sql);
    }

    public static function select(string $sql, array $bindings = []): array
    {
        return self::run($sql, $bindings)->fetchAll();
    }

    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = self::run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    // First column of the first row, or null.
    public static function scalar(string $sql, array $bindings = [])
    {
        $value = self::run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    public static function insert(string $sql, array $bindings = []): string
    {
        self::run($sql, $bindings);

        return self::connection()->lastInsertId();
    }

    public static function update(string $sql, array $bindings = []): int
    {
        return self::statement($sql, $bindings);
    }

    public static function delete(string $sql, array $bindings = []): int
    {
        return self::statement($sql, $bindings);
    }

    public static function statement(string $sql, array $bindings = []): int
    {
        return self::run($sql, $bindings)->rowCount();
    }

    // Runs $callback inside a transaction; nested calls join the outer one.
    public static function transaction(callable $callback)
    {
        $pdo = self::connection();

        if ($pdo->inTransaction()) {
            return $callback();
        }

        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public static function enableQueryLog(bool $enabled = true): void
    {
        self::$logging = $enabled;
    }

    public static function queryLog(): array
    {
        return self::$queryLog;
    }

    private static function run(string $sql, array $bindings): PDOStatement
    {
        $start = microtime(true);

        $statement = self::connection()->prepare($sql);
        $statement->execute(array_values($bindings) === $bindings ? $bindings : $bindings);

        if (self::$logging ?? (self::$logging = defined('APP_DEBUG') && APP_DEBUG)) {
            self::$queryLog[] = [
                'sql' => $sql,
                'bindings' => $bindings,
                'time' => round((microtime(true) - $start) * 1000, 2),
            ];

            if (count(self::$queryLog) > 500) {
                array_shift(self::$queryLog);
            }
        }

        return $statement;
    }
}
