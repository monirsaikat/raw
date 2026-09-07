<?php

// Fluent SQL builder over Database. Identifiers are validated and quoted,
// values are always bound, operators are whitelisted — so request data can
// safely be passed as a value but never as a column, table or operator.
//
//   Database::table('users')->where('active', 1)->orderBy('name')->get();
//   Database::table('users')->where('email', $email)->first();
//   Database::table('posts')->whereIn('id', $ids)->update(['published' => 1]);

class QueryBuilder
{
    private const OPERATORS = ['=', '<', '>', '<=', '>=', '<>', '!=', 'like', 'not like', '<=>'];
    private const JOIN_TYPES = ['INNER', 'LEFT', 'RIGHT', 'CROSS'];

    private array $columns = ['*'];
    private array $joins = [];
    private array $wheres = [];
    private array $groups = [];
    private array $orders = [];
    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private ?string $model = null;

    public function __construct(private string $table)
    {
        self::wrap($table);
    }

    // ---------------------------------------------------------- identifiers --

    public static function wrap(string|Raw $identifier): string
    {
        if ($identifier instanceof Raw) {
            return $identifier->sql;
        }

        $identifier = trim($identifier);

        if ($identifier === '*') {
            return '*';
        }

        if (preg_match('/^(.+?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $identifier, $m)) {
            return self::wrap($m[1]) . ' AS ' . self::wrapSegment($m[2]);
        }

        return implode('.', array_map(
            fn (string $segment) => $segment === '*' ? '*' : self::wrapSegment($segment),
            explode('.', $identifier)
        ));
    }

    private static function wrapSegment(string $segment): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
            throw new InvalidArgumentException("Invalid SQL identifier [$segment].");
        }

        return '`' . $segment . '`';
    }

    // -------------------------------------------------------------- select --

    public function asModel(string $class): static
    {
        $this->model = $class;

        return $this;
    }

    public function select(string|Raw|array ...$columns): static
    {
        $this->columns = [];

        return $this->addSelect(...$columns);
    }

    public function addSelect(string|Raw|array ...$columns): static
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        foreach ($columns as $column) {
            foreach (is_array($column) ? $column : [$column] as $item) {
                $this->columns[] = $item;
            }
        }

        if ($this->columns === []) {
            $this->columns = ['*'];
        }

        return $this;
    }

    public function selectRaw(string $expression): static
    {
        return $this->addSelect(new Raw($expression));
    }

    // --------------------------------------------------------------- where --

    // where('a', 1) · where('a', '>', 1) · where(['a' => 1, 'b' => 2]) ·
    // where(fn ($q) => $q->where(...)->orWhere(...)) for a parenthesised group.
    public function where($column, $operator = null, $value = null, string $boolean = 'AND'): static
    {
        return $this->addWhere($column, $operator, $value, $boolean, func_num_args() === 2);
    }

    public function orWhere($column, $operator = null, $value = null): static
    {
        return $this->addWhere($column, $operator, $value, 'OR', func_num_args() === 2);
    }

    private function addWhere($column, $operator, $value, string $boolean, bool $shorthand): static
    {
        if ($column instanceof Closure) {
            $nested = new static($this->table);
            $column($nested);
            $sql = $nested->compileWheres();

            if ($sql !== '') {
                $this->wheres[] = ['sql' => '(' . $sql . ')', 'bindings' => $nested->whereBindings(), 'boolean' => $boolean];
            }

            return $this;
        }

        if (is_array($column)) {
            foreach ($column as $key => $item) {
                $this->addWhere($key, '=', $item, $boolean, false);
            }

            return $this;
        }

        if ($shorthand) {
            $value = $operator;
            $operator = '=';
        }

        $operator = strtolower(trim((string) $operator));

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unsupported SQL operator [$operator].");
        }

        if ($value === null && $operator === '=') {
            return $this->whereNull($column, $boolean);
        }

        if ($value === null && ($operator === '<>' || $operator === '!=')) {
            return $this->whereNull($column, $boolean, true);
        }

        if ($value instanceof Raw) {
            $this->wheres[] = ['sql' => self::wrap($column) . ' ' . strtoupper($operator) . ' ' . $value->sql, 'bindings' => [], 'boolean' => $boolean];

            return $this;
        }

        $this->wheres[] = ['sql' => self::wrap($column) . ' ' . strtoupper($operator) . ' ?', 'bindings' => [$value], 'boolean' => $boolean];

        return $this;
    }

    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): static
    {
        $values = array_values($values);

        if ($values === []) {
            $this->wheres[] = ['sql' => $not ? '1 = 1' : '0 = 1', 'bindings' => [], 'boolean' => $boolean];

            return $this;
        }

        $this->wheres[] = [
            'sql' => self::wrap($column) . ($not ? ' NOT IN (' : ' IN (') . implode(', ', array_fill(0, count($values), '?')) . ')',
            'bindings' => $values,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): static
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    public function orWhereIn(string $column, array $values): static
    {
        return $this->whereIn($column, $values, 'OR');
    }

    public function orWhereNotIn(string $column, array $values): static
    {
        return $this->whereIn($column, $values, 'OR', true);
    }

    public function whereNull(string $column, string $boolean = 'AND', bool $not = false): static
    {
        $this->wheres[] = ['sql' => self::wrap($column) . ($not ? ' IS NOT NULL' : ' IS NULL'), 'bindings' => [], 'boolean' => $boolean];

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): static
    {
        return $this->whereNull($column, $boolean, true);
    }

    public function orWhereNull(string $column): static
    {
        return $this->whereNull($column, 'OR');
    }

    public function orWhereNotNull(string $column): static
    {
        return $this->whereNull($column, 'OR', true);
    }

    public function whereBetween(string $column, array $range, string $boolean = 'AND', bool $not = false): static
    {
        $this->wheres[] = [
            'sql' => self::wrap($column) . ($not ? ' NOT BETWEEN ? AND ?' : ' BETWEEN ? AND ?'),
            'bindings' => [$range[0] ?? null, $range[1] ?? null],
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function whereNotBetween(string $column, array $range, string $boolean = 'AND'): static
    {
        return $this->whereBetween($column, $range, $boolean, true);
    }

    // Trusted SQL only; use ? placeholders for values.
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): static
    {
        $this->wheres[] = ['sql' => '(' . $sql . ')', 'bindings' => array_values($bindings), 'boolean' => $boolean];

        return $this;
    }

    public function orWhereRaw(string $sql, array $bindings = []): static
    {
        return $this->whereRaw($sql, $bindings, 'OR');
    }

    // ---------------------------------------------------------------- join --

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): static
    {
        $type = strtoupper($type);

        if (!in_array($type, self::JOIN_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported join type [$type].");
        }

        if (!in_array(strtolower($operator), self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unsupported SQL operator [$operator].");
        }

        $this->joins[] = $type . ' JOIN ' . self::wrap($table) . ' ON ' . self::wrap($first) . ' ' . strtoupper($operator) . ' ' . self::wrap($second);

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    // --------------------------------------------------- order/group/limit --

    public function orderBy(string|Raw $column, string $direction = 'asc'): static
    {
        $direction = strtoupper($direction);

        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new InvalidArgumentException("Order direction must be asc or desc, got [$direction].");
        }

        $this->orders[] = self::wrap($column) . ' ' . $direction;

        return $this;
    }

    public function orderByDesc(string|Raw $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function orderByRaw(string $sql): static
    {
        $this->orders[] = $sql;

        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'asc');
    }

    public function groupBy(string ...$columns): static
    {
        foreach ($columns as $column) {
            $this->groups[] = self::wrap($column);
        }

        return $this;
    }

    public function limit(int $value): static
    {
        $this->limitValue = max(0, $value);

        return $this;
    }

    public function offset(int $value): static
    {
        $this->offsetValue = max(0, $value);

        return $this;
    }

    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(max(0, $page - 1) * $perPage)->limit($perPage);
    }

    // ------------------------------------------------------------- compile --

    public function toSql(): string
    {
        $sql = 'SELECT ' . implode(', ', array_map([self::class, 'wrap'], $this->columns))
            . ' FROM ' . self::wrap($this->table);

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . $this->compileWheres();
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limitValue !== null) {
            $sql .= ' LIMIT ' . $this->limitValue;
        }

        if ($this->offsetValue !== null) {
            $sql .= ' OFFSET ' . $this->offsetValue;
        }

        return $sql;
    }

    public function getBindings(): array
    {
        return $this->whereBindings();
    }

    private function compileWheres(): string
    {
        $sql = '';

        foreach ($this->wheres as $index => $where) {
            $sql .= ($index === 0 ? '' : ' ' . $where['boolean'] . ' ') . $where['sql'];
        }

        return $sql;
    }

    private function whereBindings(): array
    {
        return array_merge([], ...array_column($this->wheres, 'bindings'));
    }

    public function compileInsert(array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('Nothing to insert.');
        }

        $rows = is_array(reset($values)) && array_is_list($values) ? $values : [$values];
        $columns = array_keys($rows[0]);
        $placeholders = [];
        $bindings = [];

        foreach ($rows as $row) {
            $rowPlaceholders = [];

            foreach ($columns as $column) {
                $value = $row[$column] ?? null;

                if ($value instanceof Raw) {
                    $rowPlaceholders[] = $value->sql;
                } else {
                    $rowPlaceholders[] = '?';
                    $bindings[] = $value;
                }
            }

            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $sql = 'INSERT INTO ' . self::wrap($this->table)
            . ' (' . implode(', ', array_map([self::class, 'wrap'], $columns)) . ') VALUES '
            . implode(', ', $placeholders);

        return [$sql, $bindings];
    }

    public function compileUpdate(array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('Nothing to update.');
        }

        $sets = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            if ($value instanceof Raw) {
                $sets[] = self::wrap($column) . ' = ' . $value->sql;
            } else {
                $sets[] = self::wrap($column) . ' = ?';
                $bindings[] = $value;
            }
        }

        $sql = 'UPDATE ' . self::wrap($this->table) . ' SET ' . implode(', ', $sets);

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . $this->compileWheres();
            $bindings = array_merge($bindings, $this->whereBindings());
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limitValue !== null) {
            $sql .= ' LIMIT ' . $this->limitValue;
        }

        return [$sql, $bindings];
    }

    public function compileDelete(): array
    {
        $sql = 'DELETE FROM ' . self::wrap($this->table);

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . $this->compileWheres();
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limitValue !== null) {
            $sql .= ' LIMIT ' . $this->limitValue;
        }

        return [$sql, $this->whereBindings()];
    }

    // ------------------------------------------------------------- execute --

    public function get(): array
    {
        return $this->hydrate(Database::select($this->toSql(), $this->getBindings()));
    }

    public function first()
    {
        $clone = clone $this;
        $clone->limitValue = 1;

        return $clone->get()[0] ?? null;
    }

    public function find($id, string $column = 'id')
    {
        return $this->where($column, $id)->first();
    }

    public function firstOrFail()
    {
        return $this->first() ?? abort(404);
    }

    public function value(string $column)
    {
        $clone = clone $this;
        $clone->columns = [$column];
        $clone->model = null;
        $clone->limitValue = 1;

        $row = $clone->get()[0] ?? null;

        return $row === null ? null : (reset($row) ?: null);
    }

    // pluck('name') → [names]; pluck('name', 'id') → [id => name]
    public function pluck(string $column, ?string $key = null): array
    {
        $clone = clone $this;
        $clone->columns = $key === null ? [$column] : [$column, $key];
        $clone->model = null;

        $result = [];

        foreach ($clone->get() as $row) {
            $columnName = self::aliasOf($column);

            if ($key === null) {
                $result[] = $row[$columnName];
            } else {
                $result[$row[self::aliasOf($key)]] = $row[$columnName];
            }
        }

        return $result;
    }

    private static function aliasOf(string $column): string
    {
        if (preg_match('/\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $column, $m)) {
            return $m[1];
        }

        $parts = explode('.', $column);

        return end($parts);
    }

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    public function sum(string $column)
    {
        return $this->aggregate('SUM', $column) ?? 0;
    }

    public function avg(string $column)
    {
        return $this->aggregate('AVG', $column);
    }

    public function min(string $column)
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column)
    {
        return $this->aggregate('MAX', $column);
    }

    private function aggregate(string $function, string $column)
    {
        $clone = clone $this;
        $clone->columns = [new Raw($function . '(' . ($column === '*' ? '*' : self::wrap($column)) . ') AS aggregate')];
        $clone->orders = [];
        $clone->limitValue = null;
        $clone->offsetValue = null;
        $clone->model = null;

        return Database::selectOne($clone->toSql(), $clone->getBindings())['aggregate'] ?? null;
    }

    public function exists(): bool
    {
        $clone = clone $this;
        $clone->columns = [new Raw('1')];
        $clone->orders = [];
        $clone->limitValue = 1;
        $clone->model = null;

        return Database::selectOne($clone->toSql(), $clone->getBindings()) !== null;
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    // Returns ['data' => rows, 'total', 'per_page', 'current_page', 'last_page', 'from', 'to'].
    public function paginate(int $perPage = 15, ?int $page = null): array
    {
        $page = max(1, $page ?? (int) query('page', 1));
        $total = (clone $this)->count();
        $items = (clone $this)->forPage($page, $perPage)->get();
        $offset = ($page - 1) * $perPage;

        return [
            'data' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
            'from' => $items === [] ? null : $offset + 1,
            'to' => $items === [] ? null : $offset + count($items),
        ];
    }

    public function insert(array $values): bool
    {
        [$sql, $bindings] = $this->compileInsert($values);

        Database::statement($sql, $bindings);

        return true;
    }

    public function insertGetId(array $values): string
    {
        [$sql, $bindings] = $this->compileInsert($values);

        return Database::insert($sql, $bindings);
    }

    public function update(array $values): int
    {
        [$sql, $bindings] = $this->compileUpdate($values);

        return Database::update($sql, $bindings);
    }

    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->update([$column => new Raw(self::wrap($column) . ' + ' . (0 + $amount))] + $extra);
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->update([$column => new Raw(self::wrap($column) . ' - ' . (0 + $amount))] + $extra);
    }

    public function delete(): int
    {
        [$sql, $bindings] = $this->compileDelete();

        return Database::delete($sql, $bindings);
    }

    private function hydrate(array $rows): array
    {
        if ($this->model === null) {
            return $rows;
        }

        $class = $this->model;

        return array_map(fn (array $row) => $class::hydrate($row), $rows);
    }
}
