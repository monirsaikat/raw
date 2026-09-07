<?php

// Fluent SQL builder over a Connection. Identifiers are validated and
// quoted, values are always bound, operators are whitelisted — request data
// may be a value but never a column, table or operator.
//
//   Database::table('users')->where('active', 1)->orderBy('name')->get();
//   Database::table('posts')->whereIn('id', $ids)->update(['published' => 1]);
//
// When a Model class is attached (Model::query() does this) results are
// hydrated into models, global scopes apply, and the relationship helpers
// with()/whereHas()/withCount() become available.

class QueryBuilder
{
    public const OPERATORS = ['=', '<', '>', '<=', '>=', '<>', '!=', 'like', 'not like', '<=>'];
    private const JOIN_TYPES = ['INNER', 'LEFT', 'RIGHT', 'CROSS'];

    protected Connection $connection;
    protected ?string $table = null;
    protected bool $distinct = false;
    protected array $columns = ['*'];
    protected array $selectBindings = [];
    protected array $joins = [];        // ['sql' => string, 'bindings' => []]
    protected array $wheres = [];       // ['sql' => string, 'bindings' => [], 'boolean' => 'AND'|'OR']
    protected array $groups = [];
    protected array $havings = [];      // same shape as wheres
    protected array $orders = [];
    protected ?int $limitValue = null;
    protected ?int $offsetValue = null;
    protected array $unions = [];       // ['query' => QueryBuilder, 'all' => bool]
    protected ?string $lock = null;

    protected ?string $model = null;
    protected array $eagerLoad = [];    // 'relation' => Closure|null
    protected array $removedScopes = [];
    protected bool $withoutScopes = false;
    protected bool $forceDeleting = false;

    public function __construct(Connection $connection, ?string $table = null)
    {
        $this->connection = $connection;

        if ($table !== null) {
            $this->from($table);
        }
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    // from('users') or from('users as u')
    public function from(string $table): static
    {
        self::wrap($table);

        $this->table = $table;

        return $this;
    }

    public function newQuery(): static
    {
        return new static($this->connection);
    }

    public function getTable(): ?string
    {
        return $this->table;
    }

    // Alias if one was given, otherwise the table name.
    public function tableAlias(): string
    {
        if ($this->table === null) {
            return '';
        }

        return preg_match('/\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $this->table, $m) ? $m[1] : $this->table;
    }

    public function qualifyColumn(string $column): string
    {
        if (str_contains($column, '.') || $this->table === null) {
            return $column;
        }

        return $this->tableAlias() . '.' . $column;
    }

    public function asModel(string $class): static
    {
        $this->model = $class;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getColumns(): array
    {
        return $this->columns;
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

    public static function assertOperator(string $operator): string
    {
        $operator = strtolower(trim($operator));

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unsupported SQL operator [$operator].");
        }

        return $operator;
    }

    // -------------------------------------------------------------- select --

    public function select(string|Raw|array ...$columns): static
    {
        $this->columns = [];
        $this->selectBindings = [];

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

    public function selectRaw(string $expression, array $bindings = []): static
    {
        $this->addSelect(new Raw($expression));
        $this->selectBindings = array_merge($this->selectBindings, array_values($bindings));

        return $this;
    }

    // Adds a sub-select column: selectSub(fn ($q) => $q->from('posts')->selectRaw('COUNT(*)')->whereColumn(...), 'posts_count')
    public function selectSub(QueryBuilder|Closure $query, string $as): static
    {
        [$sql, $bindings] = $this->resolveSubQuery($query);

        // Keep the implicit "*" so the sub-select is added, not substituted.
        if ($this->columns === ['*']) {
            $this->columns = [new Raw('*')];
        }

        return $this->selectRaw('(' . $sql . ') AS ' . self::wrap($as), $bindings);
    }

    public function distinct(bool $value = true): static
    {
        $this->distinct = $value;

        return $this;
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

    public function whereNot($column, $operator = null, $value = null): static
    {
        $query = $this->newQuery();
        $query->addWhere($column, $operator, $value, 'AND', func_num_args() === 2);

        $this->wheres[] = ['sql' => 'NOT (' . $query->compileWheres() . ')', 'bindings' => $query->whereBindings(), 'boolean' => 'AND'];

        return $this;
    }

    private function addWhere($column, $operator, $value, string $boolean, bool $shorthand): static
    {
        if ($column instanceof Closure) {
            $nested = $this->newQuery();
            $nested->table = $this->table;
            $nested->model = $this->model;
            $nested->withoutScopes = true;
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

        $operator = self::assertOperator((string) $operator);

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

        if ($value instanceof QueryBuilder || $value instanceof Closure) {
            [$sql, $bindings] = $this->resolveSubQuery($value);
            $this->wheres[] = ['sql' => self::wrap($column) . ' ' . strtoupper($operator) . ' (' . $sql . ')', 'bindings' => $bindings, 'boolean' => $boolean];

            return $this;
        }

        $this->wheres[] = ['sql' => self::wrap($column) . ' ' . strtoupper($operator) . ' ?', 'bindings' => [$value], 'boolean' => $boolean];

        return $this;
    }

    // Column-to-column comparison: whereColumn('updated_at', '>', 'created_at')
    public function whereColumn(string $first, string $operator, ?string $second = null, string $boolean = 'AND'): static
    {
        if ($second === null) {
            $second = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'sql' => self::wrap($first) . ' ' . strtoupper(self::assertOperator($operator)) . ' ' . self::wrap($second),
            'bindings' => [],
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhereColumn(string $first, string $operator, ?string $second = null): static
    {
        return $this->whereColumn($first, $operator, $second, 'OR');
    }

    // whereIn('id', [1, 2]) or whereIn('id', fn ($q) => $q->from('posts')->select('user_id'))
    public function whereIn(string $column, array|QueryBuilder|Closure $values, string $boolean = 'AND', bool $not = false): static
    {
        if (!is_array($values)) {
            [$sql, $bindings] = $this->resolveSubQuery($values);
            $this->wheres[] = ['sql' => self::wrap($column) . ($not ? ' NOT IN (' : ' IN (') . $sql . ')', 'bindings' => $bindings, 'boolean' => $boolean];

            return $this;
        }

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

    public function whereNotIn(string $column, array|QueryBuilder|Closure $values, string $boolean = 'AND'): static
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    public function orWhereIn(string $column, array|QueryBuilder|Closure $values): static
    {
        return $this->whereIn($column, $values, 'OR');
    }

    public function orWhereNotIn(string $column, array|QueryBuilder|Closure $values): static
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

    public function orWhereBetween(string $column, array $range): static
    {
        return $this->whereBetween($column, $range, 'OR');
    }

    public function whereLike(string $column, string $value, string $boolean = 'AND', bool $not = false): static
    {
        $this->wheres[] = ['sql' => self::wrap($column) . ($not ? ' NOT LIKE ?' : ' LIKE ?'), 'bindings' => [$value], 'boolean' => $boolean];

        return $this;
    }

    public function whereNotLike(string $column, string $value, string $boolean = 'AND'): static
    {
        return $this->whereLike($column, $value, $boolean, true);
    }

    public function orWhereLike(string $column, string $value): static
    {
        return $this->whereLike($column, $value, 'OR');
    }

    // whereDate('created_at', '2026-09-07') · whereDate('created_at', '>=', $date)
    public function whereDate(string $column, $operator, $value = null, string $boolean = 'AND'): static
    {
        return $this->whereDatePart('DATE', $column, $operator, $value, $boolean, func_num_args() === 2);
    }

    public function whereYear(string $column, $operator, $value = null, string $boolean = 'AND'): static
    {
        return $this->whereDatePart('YEAR', $column, $operator, $value, $boolean, func_num_args() === 2);
    }

    public function whereMonth(string $column, $operator, $value = null, string $boolean = 'AND'): static
    {
        return $this->whereDatePart('MONTH', $column, $operator, $value, $boolean, func_num_args() === 2);
    }

    public function whereDay(string $column, $operator, $value = null, string $boolean = 'AND'): static
    {
        return $this->whereDatePart('DAY', $column, $operator, $value, $boolean, func_num_args() === 2);
    }

    private function whereDatePart(string $part, string $column, $operator, $value, string $boolean, bool $shorthand): static
    {
        if ($shorthand) {
            $value = $operator;
            $operator = '=';
        }

        $operator = strtoupper(self::assertOperator((string) $operator));

        if ($value instanceof DateTimeInterface) {
            $value = match ($part) {
                'DATE' => $value->format('Y-m-d'),
                'YEAR' => (int) $value->format('Y'),
                'MONTH' => (int) $value->format('m'),
                'DAY' => (int) $value->format('d'),
            };
        }

        $wrapped = self::wrap($column);

        if ($this->connection->driver() === 'sqlite') {
            $expression = match ($part) {
                'DATE' => "DATE($wrapped)",
                'YEAR' => "CAST(strftime('%Y', $wrapped) AS INTEGER)",
                'MONTH' => "CAST(strftime('%m', $wrapped) AS INTEGER)",
                'DAY' => "CAST(strftime('%d', $wrapped) AS INTEGER)",
            };
        } else {
            $expression = "$part($wrapped)";
        }

        $this->wheres[] = ['sql' => $expression . ' ' . $operator . ' ?', 'bindings' => [$part === 'DATE' ? (string) $value : (int) $value], 'boolean' => $boolean];

        return $this;
    }

    public function whereExists(QueryBuilder|Closure $query, string $boolean = 'AND', bool $not = false): static
    {
        [$sql, $bindings] = $this->resolveSubQuery($query);

        $this->wheres[] = ['sql' => ($not ? 'NOT EXISTS (' : 'EXISTS (') . $sql . ')', 'bindings' => $bindings, 'boolean' => $boolean];

        return $this;
    }

    public function whereNotExists(QueryBuilder|Closure $query, string $boolean = 'AND'): static
    {
        return $this->whereExists($query, $boolean, true);
    }

    public function orWhereExists(QueryBuilder|Closure $query): static
    {
        return $this->whereExists($query, 'OR');
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

    // join('profiles', 'users.id', '=', 'profiles.user_id')
    // join('profiles', fn (JoinClause $j) => $j->on(...)->where(...))
    public function join(string $table, string|Closure $first, ?string $operator = null, ?string $second = null, string $type = 'INNER'): static
    {
        $type = strtoupper($type);

        if (!in_array($type, self::JOIN_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported join type [$type].");
        }

        $clause = new JoinClause($type, $table);

        if ($first instanceof Closure) {
            $first($clause);
        } else {
            $clause->on($first, (string) $operator, (string) $second);
        }

        $this->joins[] = ['sql' => $clause->toSql(), 'bindings' => $clause->bindings()];

        return $this;
    }

    public function leftJoin(string $table, string|Closure $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function rightJoin(string $table, string|Closure $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    public function crossJoin(string $table): static
    {
        $this->joins[] = ['sql' => 'CROSS JOIN ' . self::wrap($table), 'bindings' => []];

        return $this;
    }

    // --------------------------------------------------- group/having/order --

    public function groupBy(string ...$columns): static
    {
        foreach ($columns as $column) {
            $this->groups[] = self::wrap($column);
        }

        return $this;
    }

    public function groupByRaw(string $sql): static
    {
        $this->groups[] = $sql;

        return $this;
    }

    public function having(string $column, $operator = null, $value = null, string $boolean = 'AND'): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->havings[] = [
            'sql' => self::wrap($column) . ' ' . strtoupper(self::assertOperator((string) $operator)) . ' ?',
            'bindings' => [$value],
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orHaving(string $column, $operator = null, $value = null): static
    {
        if (func_num_args() === 2) {
            return $this->having($column, '=', $operator, 'OR');
        }

        return $this->having($column, $operator, $value, 'OR');
    }

    public function havingRaw(string $sql, array $bindings = [], string $boolean = 'AND'): static
    {
        $this->havings[] = ['sql' => $sql, 'bindings' => array_values($bindings), 'boolean' => $boolean];

        return $this;
    }

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

    public function inRandomOrder(): static
    {
        $this->orders[] = $this->connection->driver() === 'sqlite' ? 'RANDOM()' : 'RAND()';

        return $this;
    }

    public function reorder(?string $column = null, string $direction = 'asc'): static
    {
        $this->orders = [];

        return $column === null ? $this : $this->orderBy($column, $direction);
    }

    public function limit(int $value): static
    {
        $this->limitValue = max(0, $value);

        return $this;
    }

    public function take(int $value): static
    {
        return $this->limit($value);
    }

    public function offset(int $value): static
    {
        $this->offsetValue = max(0, $value);

        return $this;
    }

    public function skip(int $value): static
    {
        return $this->offset($value);
    }

    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(max(0, $page - 1) * $perPage)->limit($perPage);
    }

    public function lockForUpdate(): static
    {
        $this->lock = 'update';

        return $this;
    }

    public function sharedLock(): static
    {
        $this->lock = 'share';

        return $this;
    }

    public function union(QueryBuilder|Closure $query, bool $all = false): static
    {
        if ($query instanceof Closure) {
            $callback = $query;
            $query = $this->newQuery();
            $callback($query);
        }

        $this->unions[] = ['query' => $query, 'all' => $all];

        return $this;
    }

    public function unionAll(QueryBuilder|Closure $query): static
    {
        return $this->union($query, true);
    }

    // ---------------------------------------------------------- conditional --

    // when($request->has('q'), fn ($q, $value) => $q->where('name', 'like', "%$value%"))
    public function when($condition, callable $callback, ?callable $default = null): static
    {
        if ($condition) {
            return $callback($this, $condition) ?? $this;
        }

        return $default === null ? $this : ($default($this, $condition) ?? $this);
    }

    public function unless($condition, callable $callback, ?callable $default = null): static
    {
        return $this->when(!$condition, $callback, $default);
    }

    public function tap(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    // ------------------------------------------------------------- compile --

    public function toSql(): string
    {
        return $this->applyScopes()->buildSelect();
    }

    public function getBindings(): array
    {
        return $this->applyScopes()->buildSelectBindings();
    }

    // The query with bindings substituted — for debugging only.
    public function toRawSql(): string
    {
        $sql = $this->toSql();

        foreach ($this->getBindings() as $binding) {
            $position = strpos($sql, '?');

            if ($position === false) {
                break;
            }

            $sql = substr_replace($sql, $this->connection->quote($binding), $position, 1);
        }

        return $sql;
    }

    public function dump(): static
    {
        dump($this->toRawSql());

        return $this;
    }

    public function dd(): never
    {
        dd($this->toRawSql());
    }

    protected function buildSelect(): string
    {
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '')
            . implode(', ', array_map([self::class, 'wrap'], $this->columns))
            . ($this->table !== null ? ' FROM ' . self::wrap($this->table) : '');

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', array_column($this->joins, 'sql'));
        }

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . $this->compileWheres();
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if ($this->havings !== []) {
            $sql .= ' HAVING ' . self::compileConditions($this->havings);
        }

        if ($this->unions !== []) {
            $parenthesise = $this->connection->driver() !== 'sqlite';
            $sql = $parenthesise ? '(' . $sql . ')' : $sql;

            foreach ($this->unions as $union) {
                $unionSql = $union['query']->toSql();
                $sql .= ($union['all'] ? ' UNION ALL ' : ' UNION ') . ($parenthesise ? '(' . $unionSql . ')' : $unionSql);
            }
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

        if ($this->lock !== null && $this->connection->driver() === 'mysql') {
            $sql .= $this->lock === 'update' ? ' FOR UPDATE' : ' LOCK IN SHARE MODE';
        }

        return $sql;
    }

    protected function buildSelectBindings(): array
    {
        $bindings = array_merge(
            $this->selectBindings,
            ...array_column($this->joins, 'bindings'),
        );

        $bindings = array_merge($bindings, $this->whereBindings(), ...array_column($this->havings, 'bindings'));

        foreach ($this->unions as $union) {
            $bindings = array_merge($bindings, $union['query']->getBindings());
        }

        return $bindings;
    }

    protected function compileWheres(): string
    {
        return self::compileConditions($this->wheres);
    }

    private static function compileConditions(array $conditions): string
    {
        $sql = '';

        foreach ($conditions as $index => $condition) {
            $sql .= ($index === 0 ? '' : ' ' . $condition['boolean'] . ' ') . $condition['sql'];
        }

        return $sql;
    }

    protected function whereBindings(): array
    {
        return array_merge([], ...array_column($this->wheres, 'bindings'));
    }

    private function resolveSubQuery(QueryBuilder|Closure $query): array
    {
        if ($query instanceof Closure) {
            $callback = $query;
            $query = $this->newQuery();
            $callback($query);
        }

        return [$query->toSql(), $query->getBindings()];
    }

    public function compileInsert(array $values, bool $ignore = false): array
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

        $verb = match (true) {
            !$ignore => 'INSERT INTO',
            $this->connection->driver() === 'sqlite' => 'INSERT OR IGNORE INTO',
            default => 'INSERT IGNORE INTO',
        };

        $sql = $verb . ' ' . self::wrap($this->table)
            . ' (' . implode(', ', array_map([self::class, 'wrap'], $columns)) . ') VALUES '
            . implode(', ', $placeholders);

        return [$sql, $bindings];
    }

    // INSERT ... ON DUPLICATE KEY UPDATE (MySQL) / ON CONFLICT DO UPDATE (SQLite).
    public function compileUpsert(array $values, array|string $uniqueBy, ?array $update = null): array
    {
        [$sql, $bindings] = $this->compileInsert($values);

        $rows = is_array(reset($values)) && array_is_list($values) ? $values : [$values];
        $uniqueBy = (array) $uniqueBy;
        $update ??= array_keys($rows[0]);

        if ($update === []) {
            throw new InvalidArgumentException('Upsert has no columns to update; use insertOrIgnore().');
        }

        if ($this->connection->driver() === 'sqlite') {
            $sets = array_map(fn ($column) => self::wrap($column) . ' = excluded.' . self::wrap($column), $update);
            $sql .= ' ON CONFLICT (' . implode(', ', array_map([self::class, 'wrap'], $uniqueBy)) . ') DO UPDATE SET ' . implode(', ', $sets);
        } else {
            $sets = array_map(fn ($column) => self::wrap($column) . ' = VALUES(' . self::wrap($column) . ')', $update);
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
        }

        return [$sql, $bindings];
    }

    public function compileUpdate(array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('Nothing to update.');
        }

        $query = $this->applyScopes();
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

        $sql = 'UPDATE ' . self::wrap($query->table);

        if ($query->joins !== [] && $this->connection->driver() !== 'sqlite') {
            $sql .= ' ' . implode(' ', array_column($query->joins, 'sql'));
            $bindings = array_merge(array_merge([], ...array_column($query->joins, 'bindings')), $bindings);
        }

        $sql .= ' SET ' . implode(', ', $sets);

        if ($query->wheres !== []) {
            $sql .= ' WHERE ' . $query->compileWheres();
            $bindings = array_merge($bindings, $query->whereBindings());
        }

        if ($query->orders !== [] && $this->connection->driver() !== 'sqlite') {
            $sql .= ' ORDER BY ' . implode(', ', $query->orders);
        }

        if ($query->limitValue !== null && $this->connection->driver() !== 'sqlite') {
            $sql .= ' LIMIT ' . $query->limitValue;
        }

        return [$sql, $bindings];
    }

    public function compileDelete(): array
    {
        $query = $this->applyScopes();
        $sql = 'DELETE FROM ' . self::wrap($query->table);

        if ($query->wheres !== []) {
            $sql .= ' WHERE ' . $query->compileWheres();
        }

        if ($query->orders !== [] && $this->connection->driver() !== 'sqlite') {
            $sql .= ' ORDER BY ' . implode(', ', $query->orders);
        }

        if ($query->limitValue !== null && $this->connection->driver() !== 'sqlite') {
            $sql .= ' LIMIT ' . $query->limitValue;
        }

        return [$sql, $query->whereBindings()];
    }

    // ------------------------------------------------------------- execute --

    public function get(): Collection
    {
        $query = $this->applyScopes();
        $rows = $this->connection->select($query->buildSelect(), $query->buildSelectBindings());

        if ($this->model === null) {
            return new Collection($rows);
        }

        $models = array_map(fn (array $row) => ($this->model)::hydrate($row), $rows);

        if ($models !== [] && $this->eagerLoad !== []) {
            $models = $this->eagerLoadRelations($models);
        }

        return new Collection($models);
    }

    public function first()
    {
        return (clone $this)->limit(1)->get()->first();
    }

    public function firstOrFail()
    {
        return $this->first() ?? abort(404);
    }

    public function firstOr(callable $callback)
    {
        return $this->first() ?? $callback();
    }

    // Exactly one row, or an exception.
    public function sole()
    {
        $results = (clone $this)->limit(2)->get();

        if ($results->isEmpty()) {
            throw new RuntimeException('No records found for sole().');
        }

        if ($results->count() > 1) {
            throw new RuntimeException('Multiple records found for sole().');
        }

        return $results->first();
    }

    public function find($id, ?string $column = null)
    {
        return $this->where($this->keyColumn($column), $id)->first();
    }

    public function findMany(array $ids, ?string $column = null): Collection
    {
        if ($ids === []) {
            return new Collection();
        }

        return $this->whereIn($this->keyColumn($column), $ids)->get();
    }

    public function findOrFail($id, ?string $column = null)
    {
        return $this->find($id, $column) ?? abort(404);
    }

    private function keyColumn(?string $column): string
    {
        if ($column !== null) {
            return $column;
        }

        return $this->qualifyColumn($this->model !== null ? ($this->model)::primaryKey() : 'id');
    }

    public function value(string $column)
    {
        $clone = clone $this;
        $clone->columns = [$column];
        $clone->selectBindings = [];
        $clone->model = null;
        $clone->eagerLoad = [];
        $clone->limitValue = 1;

        $row = $clone->get()->first();

        return $row === null ? null : (reset($row) === false ? null : reset($row));
    }

    // pluck('name') → [names]; pluck('name', 'id') → [id => name]
    public function pluck(string $column, ?string $key = null): Collection
    {
        $clone = clone $this;
        $clone->columns = $key === null ? [$column] : [$column, $key];
        $clone->selectBindings = [];
        $clone->model = null;
        $clone->eagerLoad = [];

        $result = [];
        $columnName = self::aliasOf($column);

        foreach ($clone->get() as $row) {
            if ($key === null) {
                $result[] = $row[$columnName];
            } else {
                $result[$row[self::aliasOf($key)]] = $row[$columnName];
            }
        }

        return new Collection($result);
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
        $query = $this->applyScopes();
        $clone = clone $query;
        $clone->withoutScopes = true;
        $clone->columns = [new Raw($function . '(' . ($clone->distinct ? 'DISTINCT ' : '') . ($column === '*' ? '*' : self::wrap($column)) . ') AS aggregate')];
        $clone->selectBindings = [];
        $clone->distinct = false;
        $clone->orders = [];
        $clone->limitValue = null;
        $clone->offsetValue = null;
        $clone->model = null;
        $clone->eagerLoad = [];

        if ($clone->groups !== [] || $clone->unions !== []) {
            // Aggregate over the grouped/union result as a derived table.
            $inner = clone $query;
            $inner->withoutScopes = true;
            $inner->model = null;
            $sql = 'SELECT ' . $function . '(*) AS aggregate FROM (' . $inner->buildSelect() . ') AS aggregate_table';

            return $this->connection->selectOne($sql, $inner->buildSelectBindings())['aggregate'] ?? null;
        }

        return $this->connection->selectOne($clone->buildSelect(), $clone->buildSelectBindings())['aggregate'] ?? null;
    }

    public function exists(): bool
    {
        $clone = clone $this->applyScopes();
        $clone->withoutScopes = true;
        $clone->columns = [new Raw('1')];
        $clone->selectBindings = [];
        $clone->orders = [];
        $clone->limitValue = 1;
        $clone->model = null;
        $clone->eagerLoad = [];

        return $this->connection->selectOne($clone->buildSelect(), $clone->buildSelectBindings()) !== null;
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    // Processes the result set in pages: chunk(200, fn (Collection $rows, int $page) => ...).
    // Return false from the callback to stop. Needs a stable order; models
    // are ordered by primary key automatically.
    public function chunk(int $count, callable $callback): bool
    {
        $query = clone $this;

        if ($query->orders === [] && $this->model !== null) {
            $query->orderBy($this->keyColumn(null));
        }

        $page = 1;

        do {
            $results = (clone $query)->forPage($page, $count)->get();
            $found = $results->count();

            if ($found === 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $page++;
        } while ($found === $count);

        return true;
    }

    // Like chunk() but keyed on an id column, safe when rows are modified.
    public function chunkById(int $count, callable $callback, ?string $column = null): bool
    {
        $column = $column ?? $this->keyColumn(null);
        $alias = self::aliasOf($column);
        $lastId = null;
        $page = 1;

        do {
            $query = (clone $this)->reorder($column)->limit($count);

            if ($lastId !== null) {
                $query->where($column, '>', $lastId);
            }

            $results = $query->get();
            $found = $results->count();

            if ($found === 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $last = $results->last();
            $lastId = $last instanceof Model ? $last->getAttribute($alias) : $last[$alias];
            $page++;
        } while ($found === $count);

        return true;
    }

    public function each(callable $callback, int $count = 1000): bool
    {
        return $this->chunk($count, function (Collection $results) use ($callback) {
            foreach ($results as $key => $item) {
                if ($callback($item, $key) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    // Streams rows/models one by one without loading everything.
    public function cursor(): Generator
    {
        $query = $this->applyScopes();

        foreach ($this->connection->cursor($query->buildSelect(), $query->buildSelectBindings()) as $row) {
            yield $this->model === null ? $row : ($this->model)::hydrate($row);
        }
    }

    public function lazy(): Generator
    {
        return $this->cursor();
    }

    public function paginate(int $perPage = 15, ?int $page = null, string $pageName = 'page'): Paginator
    {
        $page = max(1, $page ?? (int) query($pageName, 1));
        $total = (clone $this)->reorder()->count();
        $items = $total > 0 ? (clone $this)->forPage($page, $perPage)->get() : new Collection();

        return new Paginator($items, $total, $perPage, $page, [
            'page_name' => $pageName,
            'path' => request_uri(),
            'query' => query(),
        ]);
    }

    // No COUNT query: fetches one extra row to learn whether a next page exists.
    public function simplePaginate(int $perPage = 15, ?int $page = null, string $pageName = 'page'): Paginator
    {
        $page = max(1, $page ?? (int) query($pageName, 1));
        $items = (clone $this)->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();
        $hasMore = $items->count() > $perPage;

        return Paginator::simple($items->take($perPage)->values(), $perPage, $page, $hasMore, [
            'page_name' => $pageName,
            'path' => request_uri(),
            'query' => query(),
        ]);
    }

    public function insert(array $values): bool
    {
        [$sql, $bindings] = $this->compileInsert($values);

        $this->connection->statement($sql, $bindings);

        return true;
    }

    public function insertOrIgnore(array $values): int
    {
        [$sql, $bindings] = $this->compileInsert($values, true);

        return $this->connection->statement($sql, $bindings);
    }

    public function insertGetId(array $values): string
    {
        [$sql, $bindings] = $this->compileInsert($values);

        return $this->connection->insert($sql, $bindings);
    }

    // upsert([['email' => ..., 'name' => ...]], 'email', ['name'])
    public function upsert(array $values, array|string $uniqueBy, ?array $update = null): int
    {
        if ($values === []) {
            return 0;
        }

        if ($update === []) {
            return $this->insertOrIgnore($values);
        }

        [$sql, $bindings] = $this->compileUpsert($values, $uniqueBy, $update);

        return $this->connection->statement($sql, $bindings);
    }

    public function update(array $values): int
    {
        [$sql, $bindings] = $this->compileUpdate($values);

        return $this->connection->update($sql, $bindings);
    }

    public function updateOrInsert(array $attributes, array $values = []): bool
    {
        $query = (clone $this)->where($attributes);

        if ($query->exists()) {
            return $values === [] || $query->limit(1)->update($values) >= 0;
        }

        return $this->insert(array_merge($attributes, $values));
    }

    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->update([$column => new Raw(self::wrap($column) . ' + ' . (0 + $amount))] + $extra);
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->update([$column => new Raw(self::wrap($column) . ' - ' . (0 + $amount))] + $extra);
    }

    // Soft-deletes when the model uses SoftDeletes; otherwise deletes rows.
    public function delete(): int
    {
        if ($this->model !== null && !$this->forceDeleting && method_exists($this->model, 'getDeletedAtColumn')) {
            $model = $this->model;
            $time = date('Y-m-d H:i:s');
            $values = [$model::getDeletedAtColumn() => $time];

            if ($model::usesTimestamps() && $model::UPDATED_AT !== '') {
                $values[$model::UPDATED_AT] = $time;
            }

            return $this->update($values);
        }

        [$sql, $bindings] = $this->compileDelete();

        return $this->connection->delete($sql, $bindings);
    }

    public function forceDelete(): int
    {
        $query = clone $this;
        $query->forceDeleting = true;

        return $query->delete();
    }

    public function truncate(): void
    {
        if ($this->connection->driver() === 'sqlite') {
            $this->connection->statement('DELETE FROM ' . self::wrap($this->table));

            try {
                $this->connection->statement('DELETE FROM sqlite_sequence WHERE name = ?', [$this->tableAlias()]);
            } catch (QueryException) {
                // No autoincrement column on this table.
            }

            return;
        }

        $this->connection->statement('TRUNCATE TABLE ' . self::wrap($this->table));
    }

    // ---------------------------------------------------------- model-aware --

    protected function applyScopes(): static
    {
        if ($this->model === null || $this->withoutScopes) {
            return $this;
        }

        $scopes = array_diff_key(($this->model)::getGlobalScopes(), array_flip($this->removedScopes));

        $query = clone $this;
        $query->withoutScopes = true;

        foreach ($scopes as $scope) {
            $scope($query);
        }

        return $query;
    }

    public function withoutGlobalScope(string $name): static
    {
        $this->removedScopes[] = $name;

        return $this;
    }

    public function withoutGlobalScopes(?array $names = null): static
    {
        if ($names === null) {
            $this->withoutScopes = true;
        } else {
            $this->removedScopes = array_merge($this->removedScopes, $names);
        }

        return $this;
    }

    public function withTrashed(): static
    {
        return $this->withoutGlobalScope('soft_deletes');
    }

    public function onlyTrashed(): static
    {
        $this->assertModel();

        return $this->withTrashed()->whereNotNull($this->qualifyColumn(($this->model)::getDeletedAtColumn()));
    }

    public function withoutTrashed(): static
    {
        $this->assertModel();

        return $this->withTrashed()->whereNull($this->qualifyColumn(($this->model)::getDeletedAtColumn()));
    }

    // with('posts'), with(['posts', 'posts.comments' => fn ($q) => $q->latest()])
    public function with(string|array ...$relations): static
    {
        $this->assertModel();

        foreach ($relations as $relation) {
            foreach (is_array($relation) ? $relation : [$relation] as $name => $constraints) {
                if (is_int($name)) {
                    $name = $constraints;
                    $constraints = null;
                }

                // Make sure every parent of a nested relation is loaded too.
                $segments = explode('.', $name);
                $path = '';

                foreach ($segments as $segment) {
                    $path = $path === '' ? $segment : $path . '.' . $segment;

                    if (!array_key_exists($path, $this->eagerLoad)) {
                        $this->eagerLoad[$path] = null;
                    }
                }

                if ($constraints !== null) {
                    $this->eagerLoad[$name] = $constraints;
                }
            }
        }

        return $this;
    }

    public function without(string ...$relations): static
    {
        foreach ($relations as $relation) {
            unset($this->eagerLoad[$relation]);
        }

        return $this;
    }

    public function getEagerLoads(): array
    {
        return $this->eagerLoad;
    }

    // Loads every requested relation onto the given models in one query per
    // relation (no N+1). Public so Collection::load() can reuse it.
    public function eagerLoadRelations(array $models): array
    {
        foreach ($this->eagerLoad as $name => $constraints) {
            if (!str_contains($name, '.')) {
                $models = $this->eagerLoadRelation($models, $name, $constraints);
            }
        }

        return $models;
    }

    private function eagerLoadRelation(array $models, string $name, ?Closure $constraints): array
    {
        $relation = $this->relationFor($models[0], $name);

        $nested = [];

        foreach ($this->eagerLoad as $key => $value) {
            if (str_starts_with($key, $name . '.')) {
                $nested[substr($key, strlen($name) + 1)] = $value;
            }
        }

        $relation->addEagerConstraints($models);

        if ($nested !== []) {
            $relation->getQuery()->with($nested);
        }

        if ($constraints !== null) {
            $constraints($relation);
        }

        $relation->initRelation($models, $name);

        return $relation->match($models, $relation->getEager(), $name);
    }

    private function relationFor(Model $model, string $name): Relation
    {
        if (!method_exists($model, $name)) {
            throw new LogicException('Relationship [' . $name . '] is not defined on ' . get_class($model) . '.');
        }

        $relation = Relation::noConstraints(fn () => $model->$name());

        if (!$relation instanceof Relation) {
            throw new LogicException('Method [' . $name . '] on ' . get_class($model) . ' does not return a relationship.');
        }

        return $relation;
    }

    // withCount('posts'), withCount(['posts' => fn ($q) => $q->where('published', 1)])
    public function withCount(string|array ...$relations): static
    {
        $this->assertModel();

        foreach ($relations as $relation) {
            foreach (is_array($relation) ? $relation : [$relation] as $name => $constraints) {
                if (is_int($name)) {
                    $name = $constraints;
                    $constraints = null;
                }

                $alias = $name;

                if (preg_match('/^(.+?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $name, $m)) {
                    $name = $m[1];
                    $alias = $m[2];
                } else {
                    $alias = str_snake($name) . '_count';
                }

                $relation = $this->relationFor(new ($this->model)(), $name);
                $subQuery = $relation->getRelationExistenceCountQuery(($relation->getRelated())::query(), $this);

                if ($constraints !== null) {
                    $constraints($subQuery);
                }

                $this->selectSub($subQuery, $alias);
            }
        }

        return $this;
    }

    // has('posts'), has('posts', '>=', 3), has('posts.comments')
    public function has(string $relation, string $operator = '>=', int $count = 1, string $boolean = 'AND', ?Closure $callback = null): static
    {
        $this->assertModel();

        if (str_contains($relation, '.')) {
            [$first, $rest] = explode('.', $relation, 2);

            return $this->has($first, '>=', 1, $boolean, fn (QueryBuilder $q) => $q->has($rest, $operator, $count, 'AND', $callback));
        }

        $relationObject = $this->relationFor(new ($this->model)(), $relation);
        $related = $relationObject->getRelated();

        $existence = ($operator === '>=' && $count === 1) || ($operator === '<' && $count === 1);

        if ($existence) {
            $subQuery = $relationObject->getRelationExistenceQuery($related::query(), $this)->select(new Raw('1'));

            if ($callback !== null) {
                $callback($subQuery);
            }

            return $this->whereExists($subQuery, $boolean, $operator === '<');
        }

        $subQuery = $relationObject->getRelationExistenceCountQuery($related::query(), $this);

        if ($callback !== null) {
            $callback($subQuery);
        }

        $this->wheres[] = [
            'sql' => '(' . $subQuery->toSql() . ') ' . strtoupper(self::assertOperator($operator)) . ' ?',
            'bindings' => array_merge($subQuery->getBindings(), [$count]),
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orHas(string $relation, string $operator = '>=', int $count = 1): static
    {
        return $this->has($relation, $operator, $count, 'OR');
    }

    public function doesntHave(string $relation, string $boolean = 'AND', ?Closure $callback = null): static
    {
        return $this->has($relation, '<', 1, $boolean, $callback);
    }

    public function whereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1): static
    {
        return $this->has($relation, $operator, $count, 'AND', $callback);
    }

    public function orWhereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1): static
    {
        return $this->has($relation, $operator, $count, 'OR', $callback);
    }

    public function whereDoesntHave(string $relation, ?Closure $callback = null): static
    {
        return $this->doesntHave($relation, 'AND', $callback);
    }

    private function assertModel(): void
    {
        if ($this->model === null) {
            throw new LogicException('This query has no model; use Model::query() for relationship features.');
        }
    }

    // Local scopes: User::active() calls User::scopeActive($query).
    public function __call(string $method, array $arguments)
    {
        if ($this->model !== null) {
            $scope = 'scope' . ucfirst($method);

            if (method_exists($this->model, $scope)) {
                $result = (new ($this->model)())->$scope($this, ...$arguments);

                return $result instanceof QueryBuilder ? $result : $this;
            }
        }

        throw new BadMethodCallException('Call to undefined method ' . static::class . '::' . $method . '().');
    }
}
