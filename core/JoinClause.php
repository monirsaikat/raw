<?php

// A JOIN with one or more conditions, built by the closure form of join():
//   ->join('profiles', function (JoinClause $join) {
//       $join->on('users.id', '=', 'profiles.user_id')->where('profiles.active', 1);
//   })

class JoinClause
{
    public array $conditions = [];   // ['sql' => string, 'bindings' => [], 'boolean' => 'AND'|'OR']

    public function __construct(public string $type, public string $table)
    {
    }

    // Column-to-column condition.
    public function on(string $first, string $operator, string $second, string $boolean = 'AND'): static
    {
        QueryBuilder::assertOperator($operator);

        $this->conditions[] = [
            'sql' => QueryBuilder::wrap($first) . ' ' . strtoupper($operator) . ' ' . QueryBuilder::wrap($second),
            'bindings' => [],
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orOn(string $first, string $operator, string $second): static
    {
        return $this->on($first, $operator, $second, 'OR');
    }

    // Column-to-value condition (bound).
    public function where(string $column, $operator, $value = null, string $boolean = 'AND'): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        QueryBuilder::assertOperator($operator);

        if ($value === null) {
            return $this->whereNull($column, $boolean, in_array($operator, ['!=', '<>'], true));
        }

        $this->conditions[] = [
            'sql' => QueryBuilder::wrap($column) . ' ' . strtoupper($operator) . ' ?',
            'bindings' => [$value],
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhere(string $column, $operator, $value = null): static
    {
        if (func_num_args() === 2) {
            return $this->where($column, '=', $operator, 'OR');
        }

        return $this->where($column, $operator, $value, 'OR');
    }

    public function whereNull(string $column, string $boolean = 'AND', bool $not = false): static
    {
        $this->conditions[] = [
            'sql' => QueryBuilder::wrap($column) . ($not ? ' IS NOT NULL' : ' IS NULL'),
            'bindings' => [],
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): static
    {
        return $this->whereNull($column, $boolean, true);
    }

    public function toSql(): string
    {
        $sql = $this->type . ' JOIN ' . QueryBuilder::wrap($this->table);

        if ($this->conditions === []) {
            return $sql;
        }

        $on = '';

        foreach ($this->conditions as $index => $condition) {
            $on .= ($index === 0 ? '' : ' ' . $condition['boolean'] . ' ') . $condition['sql'];
        }

        return $sql . ' ON ' . $on;
    }

    public function bindings(): array
    {
        return array_merge([], ...array_column($this->conditions, 'bindings'));
    }
}
