<?php

// Active-record base class. A model is a row: attributes are read with
// $user->name or $user['name'] (ArrayAccess keeps templates and existing
// array-style code working), saved with save(), and queried through the
// query builder — any static call the model does not define is forwarded:
//
//   User::find(1);  User::where('active', 1)->latest()->get();  User::count();
//   $user = User::create(['name' => 'Ann', 'email' => 'a@b.c']);
//   $user->update(['name' => 'Anna']);  $user->delete();
//
// Mass assignment (create/fill/update) only accepts $fillable columns, or
// everything except $guarded when $fillable is empty. Direct property sets
// bypass that. $hidden columns are left out of toArray()/JSON.

abstract class Model implements ArrayAccess, JsonSerializable
{
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    protected static string $table = '';
    protected static string $primaryKey = 'id';
    protected static array $fillable = [];
    protected static array $guarded = ['id'];
    protected static array $hidden = [];
    protected static bool $timestamps = true;

    protected array $attributes = [];
    protected array $original = [];
    public bool $exists = false;

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    // ---------------------------------------------------------------- meta --

    public static function table(): string
    {
        static $tables = [];

        if (static::$table !== '') {
            return static::$table;
        }

        return $tables[static::class] ??= str_plural(str_snake(class_basename(static::class)));
    }

    public static function primaryKey(): string
    {
        return static::$primaryKey;
    }

    public function getKey()
    {
        return $this->attributes[static::$primaryKey] ?? null;
    }

    // --------------------------------------------------------------- query --

    public static function query(): QueryBuilder
    {
        return Database::table(static::table())->asModel(static::class);
    }

    public static function __callStatic(string $method, array $arguments)
    {
        return static::query()->$method(...$arguments);
    }

    public static function all(): array
    {
        return static::query()->get();
    }

    public static function find($id): ?static
    {
        if ($id === null || $id === '') {
            return null;
        }

        return static::query()->find($id, static::$primaryKey);
    }

    public static function findOrFail($id): static
    {
        return static::find($id) ?? abort(404);
    }

    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    // Builds a model from a database row (bypasses fillable).
    public static function hydrate(array $row): static
    {
        $model = new static();
        $model->attributes = $row;
        $model->original = $row;
        $model->exists = true;

        return $model;
    }

    // ---------------------------------------------------------- attributes --

    public function fill(array $attributes): static
    {
        foreach (static::fillableFrom($attributes) as $key => $value) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    protected static function fillableFrom(array $attributes): array
    {
        if (static::$fillable !== []) {
            return array_intersect_key($attributes, array_flip(static::$fillable));
        }

        return array_diff_key($attributes, array_flip(static::$guarded));
    }

    public function getAttribute(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function setAttribute(string $key, $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getOriginal(?string $key = null)
    {
        return $key === null ? $this->original : ($this->original[$key] ?? null);
    }

    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    public function isDirty(?string $key = null): bool
    {
        $dirty = $this->getDirty();

        return $key === null ? $dirty !== [] : array_key_exists($key, $dirty);
    }

    // ------------------------------------------------------------- persist --

    public function save(): bool
    {
        if ($this->exists) {
            $dirty = $this->getDirty();

            if ($dirty === []) {
                return true;
            }

            if (static::$timestamps && static::UPDATED_AT !== '') {
                $dirty[static::UPDATED_AT] = $this->attributes[static::UPDATED_AT] = date('Y-m-d H:i:s');
            }

            static::query()->where(static::$primaryKey, $this->getOriginal(static::$primaryKey) ?? $this->getKey())->update($dirty);
        } else {
            if (static::$timestamps) {
                $now = date('Y-m-d H:i:s');

                if (static::CREATED_AT !== '') {
                    $this->attributes[static::CREATED_AT] ??= $now;
                }

                if (static::UPDATED_AT !== '') {
                    $this->attributes[static::UPDATED_AT] ??= $now;
                }
            }

            $id = static::query()->insertGetId($this->attributes);

            if (!isset($this->attributes[static::$primaryKey]) && $id !== '' && $id !== '0') {
                $this->attributes[static::$primaryKey] = is_numeric($id) ? (int) $id : $id;
            }

            $this->exists = true;
        }

        $this->original = $this->attributes;

        return true;
    }

    public function update(array $attributes): bool
    {
        return $this->fill($attributes)->save();
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        static::query()->where(static::$primaryKey, $this->getKey())->delete();

        $this->exists = false;

        return true;
    }

    // Reloads the row from the database.
    public function refresh(): static
    {
        $fresh = static::find($this->getKey());

        if ($fresh !== null) {
            $this->attributes = $fresh->attributes;
            $this->original = $fresh->attributes;
            $this->exists = true;
        }

        return $this;
    }

    // ------------------------------------------------------------- convert --

    public function toArray(): array
    {
        return array_diff_key($this->attributes, array_flip(static::$hidden));
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }

    // --------------------------------------------------------------- magic --

    public function __get(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->attributes[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }
}
