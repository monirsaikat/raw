<?php

// Active-record base class. A model is a row: attributes are read with
// $user->name or $user['name'] (ArrayAccess keeps templates working), saved
// with save(), and queried through the query builder — any static call the
// model does not define is forwarded to Model::query():
//
//   User::find(1);  User::where('active', 1)->latest()->get();  User::count();
//   $user = User::create(['name' => 'Ann', 'email' => 'a@b.c']);
//   $user->update(['name' => 'Anna']);  $user->delete();
//   $user->posts;  $user->posts()->create([...]);  User::with('posts')->get();
//
// Features, each opt-in through a static property or a method:
//   $fillable/$guarded  mass-assignment protection for create()/fill()/update()
//   $hidden/$visible/$appends  what toArray()/JSON exposes
//   $casts              'int', 'bool', 'float', 'array'/'json', 'datetime', 'date', ...
//   getXAttribute()/setXAttribute()  accessors and mutators
//   scopeX($query)      local scopes → Model::x()
//   boot()              register global scopes (addGlobalScope) and events
//   creating/created/updating/updated/saving/saved/deleting/deleted listeners
//   hasOne/hasMany/belongsTo/belongsToMany relationships
//   use SoftDeletes;    trashed rows are hidden and restorable

abstract class Model implements ArrayAccess, JsonSerializable, Stringable
{
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    public const EVENTS = [
        'retrieved', 'creating', 'created', 'updating', 'updated', 'saving', 'saved',
        'deleting', 'deleted', 'restoring', 'restored', 'forceDeleting', 'forceDeleted',
    ];

    protected static string $table = '';
    protected static string $primaryKey = 'id';
    protected static ?string $connection = null;
    protected static array $fillable = [];
    protected static array $guarded = ['id'];
    protected static array $hidden = [];
    protected static array $visible = [];
    protected static array $appends = [];
    protected static array $casts = [];
    protected static array $with = [];
    protected static bool $timestamps = true;

    private static array $booted = [];
    private static array $globalScopes = [];
    private static array $listeners = [];
    private static bool $eventsMuted = false;

    protected array $attributes = [];
    protected array $original = [];
    protected array $changes = [];
    protected array $relations = [];
    public bool $exists = false;
    public bool $wasRecentlyCreated = false;

    public function __construct(array $attributes = [])
    {
        static::bootIfNotBooted();

        $this->fill($attributes);
    }

    // ------------------------------------------------------------- booting --

    protected static function bootIfNotBooted(): void
    {
        if (isset(self::$booted[static::class])) {
            return;
        }

        self::$booted[static::class] = true;

        static::boot();
        static::bootTraits();
    }

    // Override to register global scopes and event listeners.
    protected static function boot(): void
    {
    }

    // Traits may define bootTraitName() to hook in (see SoftDeletes).
    private static function bootTraits(): void
    {
        foreach (self::classUsesRecursive(static::class) as $trait) {
            $method = 'boot' . class_basename($trait);

            if (method_exists(static::class, $method)) {
                static::$method();
            }
        }
    }

    private static function classUsesRecursive(string $class): array
    {
        $traits = [];

        foreach (array_reverse(class_parents($class) ?: []) + [$class => $class] as $current) {
            $traits = array_merge($traits, self::traitUsesRecursive($current));
        }

        return array_unique($traits);
    }

    private static function traitUsesRecursive(string $class): array
    {
        $traits = class_uses($class) ?: [];

        foreach ($traits as $trait) {
            $traits = array_merge($traits, self::traitUsesRecursive($trait));
        }

        return $traits;
    }

    // Forgets booted state, scopes and listeners (tests).
    public static function flushState(): void
    {
        self::$booted = [];
        self::$globalScopes = [];
        self::$listeners = [];
        self::$eventsMuted = false;
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

    public static function connectionName(): ?string
    {
        return static::$connection;
    }

    public static function usesTimestamps(): bool
    {
        return static::$timestamps;
    }

    // "user_id" — the column other tables use to point at this model.
    public static function foreignKey(): string
    {
        return str_snake(class_basename(static::class)) . '_' . static::$primaryKey;
    }

    public function getKey()
    {
        return $this->attributes[static::$primaryKey] ?? null;
    }

    public function getKeyName(): string
    {
        return static::$primaryKey;
    }

    public function getTable(): string
    {
        return static::table();
    }

    public function getForeignKey(): string
    {
        return static::foreignKey();
    }

    public function qualifyColumn(string $column): string
    {
        return str_contains($column, '.') ? $column : static::table() . '.' . $column;
    }

    // --------------------------------------------------------------- scopes --

    public static function addGlobalScope(string $name, Closure $scope): void
    {
        self::$globalScopes[static::class][$name] = $scope;
    }

    public static function getGlobalScopes(): array
    {
        static::bootIfNotBooted();

        return self::$globalScopes[static::class] ?? [];
    }

    public static function hasGlobalScope(string $name): bool
    {
        return isset(static::getGlobalScopes()[$name]);
    }

    // --------------------------------------------------------------- events --

    // User::listen('created', fn (User $user) => ...) — or User::created(fn ...).
    // Returning false from a *ing listener cancels the operation.
    public static function listen(string $event, callable $listener): void
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException("Unknown model event [$event].");
        }

        self::$listeners[static::class][$event][] = $listener;
    }

    // An observer is an object with methods named after events.
    public static function observe(string|object $observer): void
    {
        $observer = is_string($observer) ? new $observer() : $observer;

        foreach (self::EVENTS as $event) {
            if (method_exists($observer, $event)) {
                static::listen($event, [$observer, $event]);
            }
        }
    }

    public static function withoutEvents(callable $callback)
    {
        $previous = self::$eventsMuted;
        self::$eventsMuted = true;

        try {
            return $callback();
        } finally {
            self::$eventsMuted = $previous;
        }
    }

    protected function fireEvent(string $event): bool
    {
        if (self::$eventsMuted) {
            return true;
        }

        foreach (self::$listeners[static::class][$event] ?? [] as $listener) {
            if ($listener($this) === false) {
                return false;
            }
        }

        return true;
    }

    // ---------------------------------------------------------------- query --

    public static function query(): QueryBuilder
    {
        static::bootIfNotBooted();

        $query = Database::table(static::table(), static::$connection)->asModel(static::class);

        if (static::$with !== []) {
            $query->with(static::$with);
        }

        return $query;
    }

    public static function newQueryWithoutScopes(): QueryBuilder
    {
        return static::query()->withoutGlobalScopes();
    }

    public static function on(?string $connection): QueryBuilder
    {
        static::bootIfNotBooted();

        return Database::table(static::table(), $connection)->asModel(static::class);
    }

    public function newQuery(): QueryBuilder
    {
        return static::query();
    }

    public static function __callStatic(string $method, array $arguments)
    {
        if (in_array($method, self::EVENTS, true) && count($arguments) === 1 && is_callable($arguments[0])) {
            static::listen($method, $arguments[0]);

            return null;
        }

        return static::query()->$method(...$arguments);
    }

    public function __call(string $method, array $arguments)
    {
        return $this->newQuery()->$method(...$arguments);
    }

    public static function all(): Collection
    {
        return static::query()->get();
    }

    public static function find($id): ?static
    {
        if ($id === null || $id === '') {
            return null;
        }

        return static::query()->find($id);
    }

    public static function findMany(array $ids): Collection
    {
        return static::query()->findMany($ids);
    }

    public static function findOrFail($id): static
    {
        return static::find($id) ?? abort(404);
    }

    public static function create(array $attributes = []): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    // Bypasses $fillable.
    public static function forceCreate(array $attributes): static
    {
        $model = new static();
        $model->forceFill($attributes)->save();

        return $model;
    }

    public static function firstOrNew(array $attributes, array $values = []): static
    {
        return static::query()->where($attributes)->first() ?? new static(array_merge($attributes, $values));
    }

    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        return static::query()->where($attributes)->first() ?? static::create(array_merge($attributes, $values));
    }

    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $model = static::firstOrNew($attributes);
        $model->fill($values)->save();

        return $model;
    }

    // Deletes by key(s), firing model events. Returns how many were deleted.
    public static function destroy(int|string|array|Collection $ids): int
    {
        $ids = $ids instanceof Collection ? $ids->all() : (array) $ids;
        $count = 0;

        foreach (static::findMany($ids) as $model) {
            if ($model->delete()) {
                $count++;
            }
        }

        return $count;
    }

    // Builds a model from a database row (bypasses fillable and mutators).
    public static function hydrate(array $row): static
    {
        static::bootIfNotBooted();

        $model = new static();
        $model->attributes = $row;
        $model->original = $row;
        $model->exists = true;
        $model->fireEvent('retrieved');

        return $model;
    }

    public static function factory(?int $count = null): Factory
    {
        $factory = Factory::factoryForModel(static::class);

        return $count === null ? $factory : $factory->count($count);
    }

    // ----------------------------------------------------------- attributes --

    public function fill(array $attributes): static
    {
        foreach (static::fillableFrom($attributes) as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
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

    public static function getFillable(): array
    {
        return static::$fillable;
    }

    // Attribute (cast, through its accessor) or loaded/lazy relation.
    public function getAttribute(string $key)
    {
        if (array_key_exists($key, $this->attributes) || $this->hasGetMutator($key)) {
            return $this->getAttributeValue($key);
        }

        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        if (method_exists($this, $key) && !method_exists(self::class, $key)) {
            return $this->getRelationValue($key);
        }

        return null;
    }

    public function getAttributeValue(string $key)
    {
        $value = $this->attributes[$key] ?? null;

        if ($this->hasGetMutator($key)) {
            return $this->{'get' . str_studly($key) . 'Attribute'}($value);
        }

        return $this->castAttribute($key, $value);
    }

    public function getRawAttribute(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function setAttribute(string $key, $value): static
    {
        if ($this->hasSetMutator($key)) {
            $this->{'set' . str_studly($key) . 'Attribute'}($value);

            return $this;
        }

        $this->attributes[$key] = $this->castForStorage($key, $value);

        return $this;
    }

    public function setRawAttribute(string $key, $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    protected function hasGetMutator(string $key): bool
    {
        return method_exists($this, 'get' . str_studly($key) . 'Attribute');
    }

    protected function hasSetMutator(string $key): bool
    {
        return method_exists($this, 'set' . str_studly($key) . 'Attribute');
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function only(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->getAttribute($key);
        }

        return $result;
    }

    public function except(array $keys): array
    {
        return array_diff_key($this->attributesToArray(), array_flip($keys));
    }

    // ---------------------------------------------------------------- casts --

    // Declared casts plus automatic datetime casts for the timestamp columns.
    public static function getCasts(): array
    {
        static $cache = [];

        if (isset($cache[static::class])) {
            return $cache[static::class];
        }

        $casts = static::$casts;

        $dates = static::$timestamps ? [static::CREATED_AT, static::UPDATED_AT] : [];

        if (method_exists(static::class, 'getDeletedAtColumn')) {
            $dates[] = static::getDeletedAtColumn();
        }

        foreach ($dates as $column) {
            if ($column !== '' && !isset($casts[$column])) {
                $casts[$column] = 'datetime';
            }
        }

        return $cache[static::class] = $casts;
    }

    protected function castAttribute(string $key, $value)
    {
        $cast = static::getCasts()[$key] ?? null;

        if ($cast === null || $value === null) {
            return $value;
        }

        [$type, $parameter] = array_pad(explode(':', $cast, 2), 2, null);

        return match ($type) {
            'int', 'integer' => (int) $value,
            'real', 'float', 'double' => (float) $value,
            'decimal' => number_format((float) $value, (int) ($parameter ?? 2), '.', ''),
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => is_string($value) ? json_decode($value, true) : $value,
            'object' => is_string($value) ? json_decode($value) : $value,
            'collection' => new Collection(is_string($value) ? (json_decode($value, true) ?? []) : (array) $value),
            'date' => DateTimeValue::parse($value)?->startOfDay(),
            'datetime' => DateTimeValue::parse($value),
            'timestamp' => DateTimeValue::parse($value)?->getTimestamp(),
            default => throw new InvalidArgumentException("Unknown cast [$cast] for attribute [$key]."),
        };
    }

    // Converts a PHP value into what the database column stores.
    protected function castForStorage(string $key, $value)
    {
        $cast = static::getCasts()[$key] ?? null;
        $type = $cast === null ? null : explode(':', $cast, 2)[0];

        if ($value instanceof DateTimeInterface) {
            return $value->format($type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
        }

        if ($value === null) {
            return null;
        }

        return match ($type) {
            'array', 'json', 'object', 'collection' => is_string($value)
                ? $value
                : json_encode($value instanceof Collection ? $value->toArray() : $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'bool', 'boolean' => (int) (bool) $value,
            'int', 'integer' => is_numeric($value) ? (int) $value : $value,
            'real', 'float', 'double' => is_numeric($value) ? (float) $value : $value,
            'date' => DateTimeValue::parse($value)?->format('Y-m-d') ?? $value,
            'datetime' => (string) (DateTimeValue::parse($value) ?? $value),
            'timestamp' => is_numeric($value) ? (int) $value : (DateTimeValue::parse($value)?->getTimestamp() ?? $value),
            default => $value instanceof Collection ? json_encode($value->toArray()) : $value,
        };
    }

    // ---------------------------------------------------------------- dirty --

    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!$this->originalIsEquivalent($key)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    protected function originalIsEquivalent(string $key): bool
    {
        if (!array_key_exists($key, $this->original)) {
            return false;
        }

        $current = $this->attributes[$key];
        $original = $this->original[$key];

        if ($current === $original) {
            return true;
        }

        if ($current === null || $original === null) {
            return false;
        }

        if (is_numeric($current) && is_numeric($original)) {
            return (string) $current === (string) $original || $current == $original;
        }

        return false;
    }

    public function isDirty(?string $key = null): bool
    {
        $dirty = $this->getDirty();

        return $key === null ? $dirty !== [] : array_key_exists($key, $dirty);
    }

    public function isClean(?string $key = null): bool
    {
        return !$this->isDirty($key);
    }

    // Attributes changed by the last save().
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function wasChanged(?string $key = null): bool
    {
        return $key === null ? $this->changes !== [] : array_key_exists($key, $this->changes);
    }

    public function getOriginal(?string $key = null)
    {
        return $key === null ? $this->original : ($this->original[$key] ?? null);
    }

    public function syncOriginal(): static
    {
        $this->original = $this->attributes;

        return $this;
    }

    // -------------------------------------------------------------- persist --

    public function save(): bool
    {
        static::bootIfNotBooted();

        if (!$this->fireEvent('saving')) {
            return false;
        }

        $saved = $this->exists ? $this->performUpdate() : $this->performInsert();

        if ($saved) {
            $this->fireEvent('saved');
        }

        return $saved;
    }

    public function saveQuietly(): bool
    {
        return static::withoutEvents(fn () => $this->save());
    }

    protected function performInsert(): bool
    {
        if (!$this->fireEvent('creating')) {
            return false;
        }

        if (static::$timestamps) {
            $now = $this->freshTimestamp();

            foreach ([static::CREATED_AT, static::UPDATED_AT] as $column) {
                if ($column !== '' && !isset($this->attributes[$column])) {
                    $this->attributes[$column] = $now;
                }
            }
        }

        $attributes = $this->attributes;

        if ($attributes === []) {
            $attributes = [static::$primaryKey => null];
        }

        $id = static::newQueryWithoutScopes()->insertGetId($attributes);

        if (($this->attributes[static::$primaryKey] ?? null) === null && $id !== '' && $id !== '0') {
            $this->attributes[static::$primaryKey] = is_numeric($id) ? (int) $id : $id;
        }

        $this->exists = true;
        $this->wasRecentlyCreated = true;
        $this->changes = [];
        $this->syncOriginal();
        $this->fireEvent('created');

        return true;
    }

    protected function performUpdate(): bool
    {
        if (!$this->fireEvent('updating')) {
            return false;
        }

        if ($this->isDirty() && static::$timestamps && static::UPDATED_AT !== '' && !$this->isDirty(static::UPDATED_AT)) {
            $this->attributes[static::UPDATED_AT] = $this->freshTimestamp();
        }

        $dirty = $this->getDirty();

        if ($dirty === []) {
            return true;
        }

        static::newQueryWithoutScopes()
            ->where(static::$primaryKey, $this->getOriginal(static::$primaryKey) ?? $this->getKey())
            ->update($dirty);

        $this->changes = $dirty;
        $this->syncOriginal();
        $this->fireEvent('updated');

        return true;
    }

    public function update(array $attributes = []): bool
    {
        if (!$this->exists) {
            return false;
        }

        return $this->fill($attributes)->save();
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        static::bootIfNotBooted();

        if (!$this->fireEvent('deleting')) {
            return false;
        }

        $this->performDeleteOnModel();
        $this->fireEvent('deleted');

        return true;
    }

    protected function performDeleteOnModel(): void
    {
        static::newQueryWithoutScopes()->where(static::$primaryKey, $this->getKey())->forceDelete();

        $this->exists = false;
    }

    public function forceDelete(): bool
    {
        return $this->delete();
    }

    // Reloads attributes (and drops loaded relations) from the database.
    public function refresh(): static
    {
        $fresh = $this->fresh();

        if ($fresh !== null) {
            $this->attributes = $fresh->attributes;
            $this->original = $fresh->attributes;
            $this->relations = [];
            $this->exists = true;
        }

        return $this;
    }

    // A new instance of this row from the database, or null if it is gone.
    public function fresh(): ?static
    {
        if (!$this->exists) {
            return null;
        }

        return static::newQueryWithoutScopes()->with(array_keys($this->relations))->find($this->getKey());
    }

    // An unsaved copy without key and timestamps.
    public function replicate(array $except = []): static
    {
        $except = array_merge($except, [static::$primaryKey, static::CREATED_AT, static::UPDATED_AT]);

        $copy = new static();
        $copy->attributes = array_diff_key($this->attributes, array_flip($except));
        $copy->relations = $this->relations;

        return $copy;
    }

    public function touch(): bool
    {
        if (!static::$timestamps || !$this->exists) {
            return false;
        }

        $this->attributes[static::UPDATED_AT] = $this->freshTimestamp();

        return $this->save();
    }

    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->incrementOrDecrement($column, $amount, $extra, '+');
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->incrementOrDecrement($column, $amount, $extra, '-');
    }

    private function incrementOrDecrement(string $column, int|float $amount, array $extra, string $sign): int
    {
        if (static::$timestamps && static::UPDATED_AT !== '' && !isset($extra[static::UPDATED_AT])) {
            $extra[static::UPDATED_AT] = $this->freshTimestamp();
        }

        $query = static::newQueryWithoutScopes()->where(static::$primaryKey, $this->getKey());
        $affected = $sign === '+' ? $query->increment($column, $amount, $extra) : $query->decrement($column, $amount, $extra);

        $this->attributes[$column] = ($this->attributes[$column] ?? 0) + ($sign === '+' ? $amount : -$amount);

        foreach ($extra as $key => $value) {
            $this->attributes[$key] = $value;
        }

        $this->syncOriginal();

        return $affected;
    }

    public function freshTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }

    public function is(?Model $other): bool
    {
        return $other !== null
            && $other::class === static::class
            && $this->getKey() !== null
            && $this->getKey() == $other->getKey();
    }

    public function isNot(?Model $other): bool
    {
        return !$this->is($other);
    }

    // ------------------------------------------------------------ relations --

    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        return new HasOne($related::query(), $this, $foreignKey ?? static::foreignKey(), $localKey ?? static::$primaryKey);
    }

    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        return new HasMany($related::query(), $this, $foreignKey ?? static::foreignKey(), $localKey ?? static::$primaryKey);
    }

    // Foreign key defaults to "<relation>_id" from the calling method's name.
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null, ?string $relation = null): BelongsTo
    {
        $relation ??= $this->guessRelationName();

        return new BelongsTo(
            $related::query(),
            $this,
            $foreignKey ?? str_snake($relation) . '_' . $related::primaryKey(),
            $ownerKey ?? $related::primaryKey(),
            $relation
        );
    }

    // Pivot table defaults to the two singular model names in alphabetical
    // order ("post_tag"); keys default to "post_id" / "tag_id".
    public function belongsToMany(
        string $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        ?string $relation = null
    ): BelongsToMany {
        return new BelongsToMany(
            $related::query(),
            $this,
            $table ?? $this->joiningTable($related),
            $foreignPivotKey ?? static::foreignKey(),
            $relatedPivotKey ?? $related::foreignKey(),
            $parentKey ?? static::$primaryKey,
            $relatedKey ?? $related::primaryKey(),
            $relation ?? $this->guessRelationName()
        );
    }

    protected function joiningTable(string $related): string
    {
        $names = [str_snake(class_basename(static::class)), str_snake(class_basename($related))];
        sort($names);

        return implode('_', $names);
    }

    protected function guessRelationName(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        return $trace[2]['function'] ?? 'related';
    }

    public function getRelationValue(string $key)
    {
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        if (method_exists($this, $key) && !method_exists(self::class, $key)) {
            $relation = $this->$key();

            if ($relation instanceof Relation) {
                return $this->relations[$key] = $relation->getResults();
            }
        }

        return null;
    }

    public function relationLoaded(string $key): bool
    {
        return array_key_exists($key, $this->relations);
    }

    public function getRelation(string $key)
    {
        return $this->relations[$key] ?? null;
    }

    public function setRelation(string $key, $value): static
    {
        $this->relations[$key] = $value;

        return $this;
    }

    public function unsetRelation(string $key): static
    {
        unset($this->relations[$key]);

        return $this;
    }

    public function getRelations(): array
    {
        return $this->relations;
    }

    // Eager loads relations onto this instance: $user->load('posts', 'roles').
    public function load(string|array ...$relations): static
    {
        static::query()->with(...$relations)->eagerLoadRelations([$this]);

        return $this;
    }

    public function loadMissing(string|array ...$relations): static
    {
        $missing = [];

        foreach ($relations as $relation) {
            foreach (is_array($relation) ? $relation : [$relation] as $name => $constraint) {
                $key = is_int($name) ? $constraint : $name;
                $root = explode('.', $key)[0];

                if (!$this->relationLoaded($root)) {
                    $missing[] = is_int($name) ? $constraint : [$name => $constraint];
                }
            }
        }

        return $missing === [] ? $this : $this->load(...$missing);
    }

    // Sets "<relation>_count" attributes: $user->loadCount('posts').
    public function loadCount(string|array ...$relations): static
    {
        $fresh = static::newQueryWithoutScopes()
            ->select(static::$primaryKey)
            ->withCount(...$relations)
            ->find($this->getKey());

        if ($fresh !== null) {
            foreach ($fresh->getAttributes() as $key => $value) {
                if (str_ends_with($key, '_count')) {
                    $this->attributes[$key] = $value;
                    $this->original[$key] = $value;
                }
            }
        }

        return $this;
    }

    // -------------------------------------------------------------- convert --

    public function toArray(): array
    {
        return array_merge($this->attributesToArray(), $this->relationsToArray());
    }

    public function attributesToArray(): array
    {
        $attributes = [];

        foreach (array_keys($this->attributes) as $key) {
            $attributes[$key] = self::serializeValue($this->getAttributeValue($key));
        }

        foreach (static::$appends as $key) {
            $attributes[$key] = self::serializeValue($this->getAttributeValue($key));
        }

        if (static::$visible !== []) {
            $attributes = array_intersect_key($attributes, array_flip(static::$visible));
        }

        return array_diff_key($attributes, array_flip(static::$hidden));
    }

    public function relationsToArray(): array
    {
        $result = [];

        foreach ($this->relations as $key => $value) {
            if (in_array($key, static::$hidden, true)) {
                continue;
            }

            $result[$key] = self::serializeValue($value);
        }

        return $result;
    }

    private static function serializeValue($value)
    {
        return match (true) {
            $value instanceof Model, $value instanceof Collection => $value->toArray(),
            $value instanceof JsonSerializable => $value->jsonSerialize(),
            default => $value,
        };
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

    // ---------------------------------------------------------------- magic --

    public function __get(string $key)
    {
        return $this->getAttribute($key);
    }

    public function __set(string $key, $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return $this->getAttribute($key) !== null;
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->getAttribute((string) $offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->getAttribute((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->setAttribute((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset], $this->relations[$offset]);
    }
}
