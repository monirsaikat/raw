<?php

// A failed SQL statement, carrying the SQL and bindings so the log and the
// debug page show what actually ran instead of a bare driver message.

class QueryException extends RuntimeException
{
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        PDOException $previous
    ) {
        parent::__construct(
            $previous->getMessage() . ' (SQL: ' . self::interpolate($sql, $bindings) . ')',
            0,
            $previous
        );
    }

    public function sqlState(): ?string
    {
        $code = $this->getPrevious()?->getCode();

        return is_string($code) ? $code : null;
    }

    private static function interpolate(string $sql, array $bindings): string
    {
        foreach (array_values($bindings) as $binding) {
            $value = match (true) {
                $binding === null => 'NULL',
                is_bool($binding) => $binding ? '1' : '0',
                is_int($binding), is_float($binding) => (string) $binding,
                $binding instanceof DateTimeInterface => "'" . $binding->format('Y-m-d H:i:s') . "'",
                default => "'" . str_replace("'", "''", (string) $binding) . "'",
            };

            $position = strpos($sql, '?');

            if ($position === false) {
                break;
            }

            $sql = substr_replace($sql, $value, $position, 1);
        }

        return $sql;
    }
}
