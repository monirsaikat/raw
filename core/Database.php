<?php

class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                env('DB_HOST', '127.0.0.1'),
                env('DB_PORT', '3306'),
                env('DB_DATABASE', ''),
                env('DB_CHARSET', 'utf8mb4')
            );

            self::$connection = new PDO(
                $dsn,
                env('DB_USERNAME', 'root'),
                env('DB_PASSWORD', ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }

        return self::$connection;
    }

    // All methods below take bound parameters only — never interpolate
    // request data into $sql, or the prepared-statement protection is void.

    public static function select(string $sql, array $bindings = []): array
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll();
    }

    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        return self::select($sql, $bindings)[0] ?? null;
    }

    public static function insert(string $sql, array $bindings = []): string
    {
        self::statement($sql, $bindings);

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
        $statement = self::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    public static function transaction(callable $callback)
    {
        $pdo = self::connection();
        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }
}
