<?php

// Fluent wrapper around an array, returned by queries and relations.
// Chainable methods return new collections (except push/put/pull/forget
// which mutate). Keys are preserved by filtering methods; call values()
// to reindex. Items may be arrays, models or any objects — pluck(),
// where(), sortBy() etc. read "dot.paths" from all of them.

class Collection implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable, Stringable
{
    protected array $items;

    public function __construct(iterable $items = [])
    {
        $this->items = self::arrayFrom($items);
    }

    public static function make(iterable $items = []): static
    {
        return new static($items);
    }

    public static function wrap($value): static
    {
        if ($value instanceof self) {
            return new static($value->all());
        }

        return new static(is_array($value) ? $value : [$value]);
    }

    public static function range(int $from, int $to): static
    {
        return new static(range($from, $to));
    }

    public static function times(int $count, ?callable $callback = null): static
    {
        $items = [];

        for ($i = 1; $i <= $count; $i++) {
            $items[] = $callback === null ? $i : $callback($i);
        }

        return new static($items);
    }

    // ------------------------------------------------------------- access --

    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    public function first(?callable $callback = null, $default = null)
    {
        if ($callback === null) {
            return $this->items === [] ? $default : reset($this->items);
        }

        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                return $item;
            }
        }

        return $default;
    }

    public function last(?callable $callback = null, $default = null)
    {
        if ($callback === null) {
            return $this->items === [] ? $default : end($this->items);
        }

        return (new static(array_reverse($this->items, true)))->first($callback, $default);
    }

    public function get($key, $default = null)
    {
        return $this->items[$key] ?? $default;
    }

    public function has($key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function search($value, bool $strict = false)
    {
        if (is_callable($value)) {
            foreach ($this->items as $key => $item) {
                if ($value($item, $key)) {
                    return $key;
                }
            }

            return false;
        }

        return array_search($value, $this->items, $strict);
    }

    // ---------------------------------------------------------- mutation --

    public function push(...$values): static
    {
        foreach ($values as $value) {
            $this->items[] = $value;
        }

        return $this;
    }

    public function put($key, $value): static
    {
        $this->items[$key] = $value;

        return $this;
    }

    public function prepend($value, $key = null): static
    {
        $this->items = $key === null ? [$value, ...$this->items] : [$key => $value] + $this->items;

        return $this;
    }

    public function pull($key, $default = null)
    {
        $value = $this->items[$key] ?? $default;

        unset($this->items[$key]);

        return $value;
    }

    public function forget($keys): static
    {
        foreach ((array) $keys as $key) {
            unset($this->items[$key]);
        }

        return $this;
    }

    // ------------------------------------------------------ transformation --

    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);

        return new static(array_combine($keys, array_map($callback, $this->items, $keys)));
    }

    public function mapWithKeys(callable $callback): static
    {
        $result = [];

        foreach ($this->items as $key => $item) {
            foreach ($callback($item, $key) as $newKey => $newValue) {
                $result[$newKey] = $newValue;
            }
        }

        return new static($result);
    }

    public function flatMap(callable $callback): static
    {
        return $this->map($callback)->collapse();
    }

    public function filter(?callable $callback = null): static
    {
        if ($callback === null) {
            return new static(array_filter($this->items));
        }

        return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    public function reject(callable $callback): static
    {
        return $this->filter(fn ($item, $key) => !$callback($item, $key));
    }

    // Stops when the callback returns false.
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    // pluck('name') → [names]; pluck('name', 'id') → [id => name]; supports 'a.b'.
    public function pluck(string $value, ?string $key = null): static
    {
        $result = [];

        foreach ($this->items as $item) {
            $plucked = self::valueOf($item, $value);

            if ($key === null) {
                $result[] = $plucked;
            } else {
                $result[(string) self::valueOf($item, $key)] = $plucked;
            }
        }

        return new static($result);
    }

    public function keyBy(string|callable $key): static
    {
        $result = [];

        foreach ($this->items as $index => $item) {
            $result[(string) self::resolve($item, $key, $index)] = $item;
        }

        return new static($result);
    }

    // Groups into a collection of collections.
    public function groupBy(string|callable $key, bool $preserveKeys = false): static
    {
        $groups = [];

        foreach ($this->items as $index => $item) {
            $group = self::resolve($item, $key, $index);
            $group = is_bool($group) ? (int) $group : (string) $group;

            if ($preserveKeys) {
                $groups[$group][$index] = $item;
            } else {
                $groups[$group][] = $item;
            }
        }

        return (new static($groups))->map(fn ($items) => new static($items));
    }

    public function countBy(string|callable|null $key = null): static
    {
        $counts = [];

        foreach ($this->items as $index => $item) {
            $group = $key === null ? $item : self::resolve($item, $key, $index);
            $group = (string) $group;
            $counts[$group] = ($counts[$group] ?? 0) + 1;
        }

        return new static($counts);
    }

    public function partition(callable $callback): static
    {
        $passed = [];
        $failed = [];

        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                $passed[$key] = $item;
            } else {
                $failed[$key] = $item;
            }
        }

        return new static([new static($passed), new static($failed)]);
    }

    public function chunk(int $size): static
    {
        if ($size <= 0) {
            return new static();
        }

        return (new static(array_chunk($this->items, $size, true)))->map(fn ($chunk) => new static($chunk));
    }

    public function collapse(): static
    {
        $result = [];

        foreach ($this->items as $item) {
            if ($item instanceof self) {
                $item = $item->all();
            }

            if (is_array($item)) {
                $result = array_merge($result, array_values($item));
            }
        }

        return new static($result);
    }

    public function flatten(float $depth = INF): static
    {
        $result = [];

        foreach ($this->items as $item) {
            $item = $item instanceof self ? $item->all() : $item;

            if (!is_array($item)) {
                $result[] = $item;
            } elseif ($depth === 1.0) {
                $result = array_merge($result, array_values($item));
            } else {
                $result = array_merge($result, (new static($item))->flatten($depth - 1)->all());
            }
        }

        return new static($result);
    }

    public function merge(iterable $items): static
    {
        return new static(array_merge($this->items, self::arrayFrom($items)));
    }

    public function concat(iterable $items): static
    {
        $result = $this->values()->all();

        foreach (self::arrayFrom($items) as $item) {
            $result[] = $item;
        }

        return new static($result);
    }

    public function combine(iterable $values): static
    {
        return new static(array_combine($this->items, self::arrayFrom($values)));
    }

    public function diff(iterable $items): static
    {
        return new static(array_diff($this->items, self::arrayFrom($items)));
    }

    public function intersect(iterable $items): static
    {
        return new static(array_intersect($this->items, self::arrayFrom($items)));
    }

    public function only(array $keys): static
    {
        return new static(array_intersect_key($this->items, array_flip($keys)));
    }

    public function except(array $keys): static
    {
        return new static(array_diff_key($this->items, array_flip($keys)));
    }

    public function unique(string|callable|null $key = null, bool $strict = false): static
    {
        if ($key === null) {
            return new static(array_unique($this->items, SORT_REGULAR));
        }

        $seen = [];
        $result = [];

        foreach ($this->items as $index => $item) {
            $value = self::resolve($item, $key, $index);

            if (in_array($value, $seen, $strict)) {
                continue;
            }

            $seen[] = $value;
            $result[$index] = $item;
        }

        return new static($result);
    }

    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    public function shuffle(): static
    {
        $items = $this->items;
        shuffle($items);

        return new static($items);
    }

    public function random(?int $number = null)
    {
        if ($this->items === []) {
            return $number === null ? null : new static();
        }

        if ($number === null) {
            return $this->items[array_rand($this->items)];
        }

        $keys = (array) array_rand($this->items, min($number, count($this->items)));

        return (new static(array_intersect_key($this->items, array_flip($keys))))->values();
    }

    // ----------------------------------------------------------- slicing --

    public function take(int $limit): static
    {
        if ($limit < 0) {
            return $this->slice($limit, abs($limit));
        }

        return $this->slice(0, $limit);
    }

    public function skip(int $count): static
    {
        return $this->slice($count);
    }

    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    public function splice(int $offset, ?int $length = null, array $replacement = []): static
    {
        $removed = array_splice($this->items, $offset, $length ?? count($this->items), $replacement);

        return new static($removed);
    }

    public function pad(int $size, $value): static
    {
        return new static(array_pad($this->items, $size, $value));
    }

    // ----------------------------------------------------------- sorting --

    public function sort(?callable $callback = null): static
    {
        $items = $this->items;

        $callback === null ? asort($items) : uasort($items, $callback);

        return new static($items);
    }

    public function sortDesc(): static
    {
        $items = $this->items;
        arsort($items);

        return new static($items);
    }

    public function sortBy(string|callable $key, bool $descending = false): static
    {
        $items = $this->items;

        uasort($items, function ($a, $b) use ($key, $descending) {
            $result = self::resolve($a, $key) <=> self::resolve($b, $key);

            return $descending ? -$result : $result;
        });

        return new static($items);
    }

    public function sortByDesc(string|callable $key): static
    {
        return $this->sortBy($key, true);
    }

    public function sortKeys(bool $descending = false): static
    {
        $items = $this->items;

        $descending ? krsort($items) : ksort($items);

        return new static($items);
    }

    // --------------------------------------------------------- searching --

    public function where(string $key, $operator = null, $value = null): static
    {
        return $this->filter($this->operatorCallback(...func_get_args()));
    }

    public function whereIn(string $key, iterable $values, bool $strict = false): static
    {
        $values = self::arrayFrom($values);

        return $this->filter(fn ($item) => in_array(self::valueOf($item, $key), $values, $strict));
    }

    public function whereNotIn(string $key, iterable $values, bool $strict = false): static
    {
        $values = self::arrayFrom($values);

        return $this->reject(fn ($item) => in_array(self::valueOf($item, $key), $values, $strict));
    }

    public function whereNull(string $key): static
    {
        return $this->filter(fn ($item) => self::valueOf($item, $key) === null);
    }

    public function whereNotNull(string $key): static
    {
        return $this->filter(fn ($item) => self::valueOf($item, $key) !== null);
    }

    public function firstWhere(string $key, $operator = null, $value = null)
    {
        return $this->first($this->operatorCallback(...func_get_args()));
    }

    // contains(fn) · contains($value) · contains('key', $value) · contains('key', '>', 3)
    public function contains($key, $operator = null, $value = null): bool
    {
        if (func_num_args() === 1) {
            if (is_callable($key) && !is_string($key)) {
                return $this->first($key, $this) !== $this;
            }

            return in_array($key, $this->items);
        }

        return $this->first($this->operatorCallback(...func_get_args()), $this) !== $this;
    }

    public function doesntContain($key, $operator = null, $value = null): bool
    {
        return !$this->contains(...func_get_args());
    }

    public function every(callable $callback): bool
    {
        foreach ($this->items as $key => $item) {
            if (!$callback($item, $key)) {
                return false;
            }
        }

        return true;
    }

    public function some(callable $callback): bool
    {
        return $this->contains($callback);
    }

    // --------------------------------------------------------- aggregates --

    public function sum(string|callable|null $key = null)
    {
        $total = 0;

        foreach ($this->items as $index => $item) {
            $total += $key === null ? $item : self::resolve($item, $key, $index);
        }

        return $total;
    }

    public function avg(string|callable|null $key = null): ?float
    {
        $count = $this->count();

        return $count === 0 ? null : $this->sum($key) / $count;
    }

    public function min(string|callable|null $key = null)
    {
        $values = $key === null ? $this->items : $this->map(fn ($item, $index) => self::resolve($item, $key, $index))->all();
        $values = array_filter($values, fn ($v) => $v !== null);

        return $values === [] ? null : min($values);
    }

    public function max(string|callable|null $key = null)
    {
        $values = $key === null ? $this->items : $this->map(fn ($item, $index) => self::resolve($item, $key, $index))->all();
        $values = array_filter($values, fn ($v) => $v !== null);

        return $values === [] ? null : max($values);
    }

    public function median(string|callable|null $key = null): ?float
    {
        $values = ($key === null ? $this : $this->map(fn ($item, $index) => self::resolve($item, $key, $index)))
            ->filter(fn ($v) => $v !== null)->sort()->values()->all();
        $count = count($values);

        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        return $count % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    public function reduce(callable $callback, $initial = null)
    {
        $result = $initial;

        foreach ($this->items as $key => $item) {
            $result = $callback($result, $item, $key);
        }

        return $result;
    }

    public function implode(string $glue, ?string $key = null): string
    {
        $values = $key === null ? $this->items : $this->pluck($key)->all();

        return implode($glue, array_map(fn ($v) => (string) $v, $values));
    }

    public function join(string $glue, string $finalGlue = ''): string
    {
        if ($finalGlue === '' || $this->count() < 2) {
            return $this->implode($glue);
        }

        $items = $this->values()->all();
        $last = array_pop($items);

        return implode($glue, $items) . $finalGlue . $last;
    }

    // -------------------------------------------------------------- misc --

    public function tap(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    public function pipe(callable $callback)
    {
        return $callback($this);
    }

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

    // Primary keys of the models in the collection.
    public function modelKeys(): array
    {
        return array_values(array_map(fn ($model) => $model instanceof Model ? $model->getKey() : null, $this->items));
    }

    // Eager loads relations onto the models already in the collection.
    public function load(string|array ...$relations): static
    {
        $first = $this->first();

        if ($first instanceof Model && $this->items !== []) {
            $first::query()->with(...$relations)->eagerLoadRelations(array_values($this->items));
        }

        return $this;
    }

    // ----------------------------------------------------- serialisation --

    public function toArray(): array
    {
        return array_map(fn ($item) => match (true) {
            $item instanceof Model, $item instanceof self => $item->toArray(),
            $item instanceof JsonSerializable => $item->jsonSerialize(),
            default => $item,
        }, $this->items);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    // ----------------------------------------------------------- helpers --

    protected static function arrayFrom(iterable $items): array
    {
        if (is_array($items)) {
            return $items;
        }

        if ($items instanceof self) {
            return $items->all();
        }

        return iterator_to_array($items);
    }

    // Reads "a.b.c" from arrays, ArrayAccess objects and plain objects.
    public static function valueOf($item, string $path)
    {
        foreach (explode('.', $path) as $segment) {
            if (is_array($item) || $item instanceof ArrayAccess) {
                $item = $item[$segment] ?? null;
            } elseif (is_object($item)) {
                $item = $item->$segment ?? null;
            } else {
                return null;
            }
        }

        return $item;
    }

    protected static function resolve($item, string|callable $key, $index = null)
    {
        if (is_callable($key) && !is_string($key)) {
            return $key($item, $index);
        }

        return self::valueOf($item, $key);
    }

    protected function operatorCallback(string $key, $operator = null, $value = null): Closure
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return function ($item) use ($key, $operator, $value): bool {
            $retrieved = self::valueOf($item, $key);

            return match ($operator) {
                '=', '==' => $retrieved == $value,
                '===' => $retrieved === $value,
                '!=', '<>' => $retrieved != $value,
                '!==' => $retrieved !== $value,
                '<' => $retrieved < $value,
                '>' => $retrieved > $value,
                '<=' => $retrieved <= $value,
                '>=' => $retrieved >= $value,
                default => throw new InvalidArgumentException("Unsupported operator [$operator]."),
            };
        };
    }
}
