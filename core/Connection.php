<?php

// One database connection. Opens PDO lazily from its config, runs prepared
// statements with typed bindings, nests transactions with savepoints,
// retries deadlocks, logs queries and reports slow ones. Obtain one with
// Database::connection('name'); the static Database helpers use the default.
// Drivers: mysql (MariaDB/MySQL) and sqlite.

class Connection
{
    private ?PDO $pdo = null;
    private int $transactions = 0;
    private ?bool $logging = null;
    private array $queryLog = [];
    private array $listeners = [];

    public function __construct(private string $name, private array $config)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function driver(): string
    {
        return strtolower((string) ($this->config['driver'] ?? 'mysql'));
    }

    public function config(?string $key = null, $default = null)
    {
        return $key === null ? $this->config : ($this->config[$key] ?? $default);
    }

    // ---------------------------------------------------------- lifecycle --

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
        $this->transactions = 0;
    }

    public function reconnect(): PDO
    {
        $this->disconnect();

        return $this->pdo();
    }

    private function connect(): PDO
    {
        $options = ($this->config['options'] ?? []) + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        try {
            $pdo = new PDO(
                $this->dsn(),
                (string) ($this->config['username'] ?? ''),
                (string) ($this->config['password'] ?? ''),
                $options
            );
        } catch (PDOException $e) {
            // Rethrown without the original: its stack trace carries the
            // constructor arguments, i.e. the password.
            throw new RuntimeException("Database connection [{$this->name}] failed: " . $e->getMessage());
        }

        if ($this->driver() === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        } elseif ($this->driver() === 'mysql' && !empty($this->config['timezone'])) {
            $pdo->exec("SET time_zone = " . $pdo->quote((string) $this->config['timezone']));
        }

        return $pdo;
    }

    public function dsn(): string
    {
        $c = $this->config;

        return match ($this->driver()) {
            'sqlite' => 'sqlite:' . self::sqlitePath((string) ($c['database'] ?? ':memory:')),
            'mysql' => !empty($c['unix_socket'])
                ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $c['unix_socket'], $c['database'] ?? '', $c['charset'] ?? 'utf8mb4')
                : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $c['host'] ?? '127.0.0.1', $c['port'] ?? '3306', $c['database'] ?? '', $c['charset'] ?? 'utf8mb4'),
            default => throw new InvalidArgumentException("Unsupported database driver [{$this->driver()}]."),
        };
    }

    private static function sqlitePath(string $database): string
    {
        if ($database === ':memory:' || str_starts_with($database, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $database)) {
            return $database;
        }

        return BASE_PATH . '/' . ltrim($database, '/');
    }

    // ------------------------------------------------------------ queries --

    public function table(string $table): QueryBuilder
    {
        return (new QueryBuilder($this))->from($table);
    }

    public function query(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->fetchAll();
    }

    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    // First column of the first row, or null.
    public function scalar(string $sql, array $bindings = [])
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    // Streams rows one at a time instead of loading the whole result set.
    public function cursor(string $sql, array $bindings = []): Generator
    {
        $statement = $this->run($sql, $bindings);

        while (($row = $statement->fetch()) !== false) {
            yield $row;
        }
    }

    public function insert(string $sql, array $bindings = []): string
    {
        $this->run($sql, $bindings);

        return $this->lastInsertId();
    }

    public function update(string $sql, array $bindings = []): int
    {
        return $this->statement($sql, $bindings);
    }

    public function delete(string $sql, array $bindings = []): int
    {
        return $this->statement($sql, $bindings);
    }

    public function statement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    // Runs raw SQL without a prepared statement (DDL, multi-statement scripts).
    public function unprepared(string $sql): int
    {
        $start = microtime(true);

        try {
            $result = $this->pdo()->exec($sql);
        } catch (PDOException $e) {
            throw new QueryException($sql, [], $e);
        }

        $this->logQuery($sql, [], (microtime(true) - $start) * 1000);

        return (int) $result;
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return (string) $this->pdo()->lastInsertId($sequence);
    }

    // Quotes a value for display (toRawSql). Never build queries with it.
    public function quote($value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            $value instanceof DateTimeInterface => "'" . $value->format('Y-m-d H:i:s') . "'",
            default => "'" . str_replace("'", "''", (string) $value) . "'",
        };
    }

    // ------------------------------------------------------- transactions --

    // Runs $callback inside a transaction; nested calls become savepoints.
    // With $attempts > 1 a deadlock rolls back and retries.
    public function transaction(callable $callback, int $attempts = 1)
    {
        for ($attempt = 1; $attempt <= max(1, $attempts); $attempt++) {
            $this->beginTransaction();

            try {
                $result = $callback($this);
            } catch (Throwable $e) {
                $this->rollBack();

                if ($attempt < $attempts && self::isDeadlock($e)) {
                    continue;
                }

                throw $e;
            }

            try {
                $this->commit();
            } catch (Throwable $e) {
                $this->transactions = max(0, $this->transactions - 1);

                if ($attempt < $attempts && self::isDeadlock($e)) {
                    continue;
                }

                throw $e;
            }

            return $result;
        }

        return null;
    }

    public function beginTransaction(): void
    {
        if ($this->transactions === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT trans' . ($this->transactions + 1));
        }

        $this->transactions++;
    }

    public function commit(): void
    {
        if ($this->transactions === 1) {
            $this->pdo()->commit();
        } elseif ($this->transactions > 1) {
            $this->pdo()->exec('RELEASE SAVEPOINT trans' . $this->transactions);
        }

        $this->transactions = max(0, $this->transactions - 1);
    }

    public function rollBack(): void
    {
        if ($this->transactions === 1) {
            $this->pdo()->rollBack();
        } elseif ($this->transactions > 1) {
            $this->pdo()->exec('ROLLBACK TO SAVEPOINT trans' . $this->transactions);
        }

        $this->transactions = max(0, $this->transactions - 1);
    }

    public function transactionLevel(): int
    {
        return $this->transactions;
    }

    private static function isDeadlock(Throwable $e): bool
    {
        $message = $e->getMessage();

        foreach (['Deadlock found', 'Lock wait timeout', 'database is locked', 'deadlock detected', '40001', '1213', '1205'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------ logging --

    // fn (string $sql, array $bindings, float $milliseconds, Connection $c)
    public function listen(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function enableQueryLog(bool $enabled = true): void
    {
        $this->logging = $enabled;
    }

    public function queryLog(): array
    {
        return $this->queryLog;
    }

    public function flushQueryLog(): void
    {
        $this->queryLog = [];
    }

    private function run(string $sql, array $bindings): PDOStatement
    {
        $start = microtime(true);

        try {
            $statement = $this->pdo()->prepare($sql);
            $this->bindValues($statement, $bindings);
            $statement->execute();
        } catch (PDOException $e) {
            throw new QueryException($sql, $bindings, $e);
        }

        $this->logQuery($sql, $bindings, (microtime(true) - $start) * 1000);

        return $statement;
    }

    private function bindValues(PDOStatement $statement, array $bindings): void
    {
        $positional = array_is_list($bindings);

        foreach ($bindings as $key => $value) {
            $parameter = $positional ? $key + 1 : (str_starts_with((string) $key, ':') ? $key : ':' . $key);

            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            } elseif (is_bool($value)) {
                $value = (int) $value;
            } elseif (is_object($value) && $value instanceof Stringable) {
                $value = (string) $value;
            }

            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue($parameter, $value, $type);
        }
    }

    private function logQuery(string $sql, array $bindings, float $milliseconds): void
    {
        foreach ($this->listeners as $listener) {
            $listener($sql, $bindings, $milliseconds, $this);
        }

        $slow = (float) config('database.slow_query_ms', 0);

        if ($slow > 0 && $milliseconds >= $slow) {
            log_warning('Slow query ({ms} ms) on [{connection}]: {sql}', [
                'ms' => round($milliseconds, 1),
                'connection' => $this->name,
                'sql' => $sql,
                'bindings' => $bindings,
            ]);
        }

        $this->logging ??= (defined('APP_DEBUG') && APP_DEBUG) || (bool) config('database.log_queries', false);

        if ($this->logging) {
            $this->queryLog[] = [
                'sql' => $sql,
                'bindings' => $bindings,
                'time' => round($milliseconds, 2),
                'connection' => $this->name,
            ];

            if (count($this->queryLog) > 500) {
                array_shift($this->queryLog);
            }
        }
    }
}
