<?php

// $post->tags — rows linked through a pivot table. Each related model gets
// a `pivot` relation holding the pivot row; attach/detach/sync maintain it.

class BelongsToMany extends Relation
{
    protected array $pivotColumns = [];
    protected bool $withTimestamps = false;

    public function __construct(
        QueryBuilder $query,
        Model $parent,
        protected string $table,
        protected string $foreignPivotKey,
        protected string $relatedPivotKey,
        protected string $parentKey,
        protected string $relatedKey,
        protected string $relationName
    ) {
        $this->performJoin($query, (string) $query->getModel());

        parent::__construct($query, $parent);
    }

    protected function performJoin(QueryBuilder $query, string $related): QueryBuilder
    {
        return $query->join(
            $this->table,
            $related::table() . '.' . $this->relatedKey,
            '=',
            $this->table . '.' . $this->relatedPivotKey
        );
    }

    public function addConstraints(): void
    {
        $this->query->where($this->table . '.' . $this->foreignPivotKey, $this->parent->getAttribute($this->parentKey));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn($this->table . '.' . $this->foreignPivotKey, $this->getKeys($models, $this->parentKey));
    }

    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, new Collection());
        }

        return $models;
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[(string) $result->getRelation('pivot')->getAttribute($this->foreignPivotKey)][] = $result;
        }

        foreach ($models as $model) {
            $key = (string) $model->getAttribute($this->parentKey);

            if (isset($dictionary[$key])) {
                $model->setRelation($relation, new Collection($dictionary[$key]));
            }
        }

        return $models;
    }

    public function getResults(): Collection
    {
        if ($this->parent->getAttribute($this->parentKey) === null) {
            return new Collection();
        }

        return $this->get();
    }

    public function get(): Collection
    {
        return $this->hydratePivotRelation($this->queryWithPivotSelects()->get());
    }

    public function getEager(): Collection
    {
        return $this->get();
    }

    // The single-result and paging helpers must also carry pivot data, so
    // they go through get() instead of the plain query.
    public function first(): ?Model
    {
        $relation = clone $this;
        $relation->query->limit(1);

        return $relation->get()->first();
    }

    public function firstOrFail(): Model
    {
        return $this->first() ?? abort(404);
    }

    public function find($id): ?Model
    {
        $relation = clone $this;
        $relation->query->where(($this->related)::table() . '.' . $this->relatedKey, $id);

        return $relation->first();
    }

    public function findOrFail($id): Model
    {
        return $this->find($id) ?? abort(404);
    }

    public function paginate(int $perPage = 15, ?int $page = null, string $pageName = 'page'): Paginator
    {
        $query = $this->queryWithPivotSelects();
        $paginator = $query->paginate($perPage, $page, $pageName);
        $this->hydratePivotRelation($paginator->items());

        return $paginator;
    }

    public function simplePaginate(int $perPage = 15, ?int $page = null, string $pageName = 'page'): Paginator
    {
        $query = $this->queryWithPivotSelects();
        $paginator = $query->simplePaginate($perPage, $page, $pageName);
        $this->hydratePivotRelation($paginator->items());

        return $paginator;
    }

    protected function queryWithPivotSelects(): QueryBuilder
    {
        $query = clone $this->query;

        if ($query->getColumns() === ['*']) {
            $query->select(($this->related)::table() . '.*');
        }

        return $query->addSelect($this->aliasedPivotColumns());
    }

    protected function aliasedPivotColumns(): array
    {
        $columns = array_unique(array_merge(
            [$this->foreignPivotKey, $this->relatedPivotKey],
            $this->pivotColumns,
            $this->withTimestamps ? ['created_at', 'updated_at'] : []
        ));

        return array_map(fn ($column) => $this->table . '.' . $column . ' as pivot_' . $column, $columns);
    }

    // Moves the pivot_* columns into a Pivot model on each related model.
    protected function hydratePivotRelation(Collection $models): Collection
    {
        foreach ($models as $model) {
            $pivot = [];

            foreach ($model->getAttributes() as $key => $value) {
                if (str_starts_with($key, 'pivot_')) {
                    $pivot[substr($key, 6)] = $value;
                    unset($model[$key]);
                }
            }

            $model->syncOriginal();
            $model->setRelation('pivot', new Pivot($this->table, $pivot));
        }

        return $models;
    }

    public function getRelationExistenceQuery(QueryBuilder $query, QueryBuilder $parentQuery): QueryBuilder
    {
        $this->performJoin($query, $this->related);

        return $query->whereColumn(
            $this->table . '.' . $this->foreignPivotKey,
            $parentQuery->qualifyColumn($this->parentKey)
        );
    }

    // ----------------------------------------------------------- options --

    public function withPivot(string ...$columns): static
    {
        $this->pivotColumns = array_values(array_unique(array_merge($this->pivotColumns, $columns)));

        return $this;
    }

    public function withTimestamps(): static
    {
        $this->withTimestamps = true;

        return $this;
    }

    // ------------------------------------------------------------ writing --

    public function newPivotQuery(): QueryBuilder
    {
        return Database::table($this->table, ($this->related)::connectionName())
            ->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey));
    }

    // attach(3) · attach([3, 4]) · attach([3 => ['role' => 'editor']]) · attach($model)
    public function attach(int|string|array|Model|Collection $ids, array $attributes = []): void
    {
        $records = [];
        $now = date('Y-m-d H:i:s');

        foreach ($this->normalizeIds($ids) as $id => $extra) {
            $record = [
                $this->foreignPivotKey => $this->parent->getAttribute($this->parentKey),
                $this->relatedPivotKey => $id,
            ] + $extra + $attributes;

            if ($this->withTimestamps) {
                $record += ['created_at' => $now, 'updated_at' => $now];
            }

            $records[] = $record;
        }

        if ($records !== []) {
            Database::table($this->table, ($this->related)::connectionName())->insert($records);
        }
    }

    // detach() removes every link; detach($ids) only the given ones.
    public function detach(int|string|array|Model|Collection|null $ids = null): int
    {
        $query = $this->newPivotQuery();

        if ($ids !== null) {
            $keys = array_keys($this->normalizeIds($ids));

            if ($keys === []) {
                return 0;
            }

            $query->whereIn($this->relatedPivotKey, $keys);
        }

        return $query->delete();
    }

    // Makes the pivot table match exactly the given ids. Returns what changed.
    public function sync(array|Collection $ids, bool $detaching = true): array
    {
        $changes = ['attached' => [], 'detached' => [], 'updated' => []];
        $records = $this->normalizeIds($ids);
        $current = $this->newPivotQuery()->pluck($this->relatedPivotKey)->all();

        if ($detaching) {
            $detach = array_values(array_diff($current, array_keys($records)));

            if ($detach !== []) {
                $this->detach($detach);
                $changes['detached'] = $detach;
            }
        }

        foreach ($records as $id => $attributes) {
            if (!in_array($id, $current)) {
                $this->attach([$id => $attributes]);
                $changes['attached'][] = $id;
            } elseif ($attributes !== [] && $this->updateExistingPivot($id, $attributes) > 0) {
                $changes['updated'][] = $id;
            }
        }

        return $changes;
    }

    public function syncWithoutDetaching(array|Collection $ids): array
    {
        return $this->sync($ids, false);
    }

    // Attaches ids that are missing and detaches those already present.
    public function toggle(array|Collection $ids): array
    {
        $changes = ['attached' => [], 'detached' => []];
        $records = $this->normalizeIds($ids);
        $current = $this->newPivotQuery()->pluck($this->relatedPivotKey)->all();

        foreach ($records as $id => $attributes) {
            if (in_array($id, $current)) {
                $this->detach($id);
                $changes['detached'][] = $id;
            } else {
                $this->attach([$id => $attributes]);
                $changes['attached'][] = $id;
            }
        }

        return $changes;
    }

    public function updateExistingPivot(int|string|Model $id, array $attributes): int
    {
        if ($this->withTimestamps) {
            $attributes += ['updated_at' => date('Y-m-d H:i:s')];
        }

        return $this->newPivotQuery()
            ->where($this->relatedPivotKey, $id instanceof Model ? $id->getAttribute($this->relatedKey) : $id)
            ->update($attributes);
    }

    // → [id => pivotAttributes]
    protected function normalizeIds(int|string|array|Model|Collection $ids): array
    {
        if ($ids instanceof Model) {
            return [$ids->getAttribute($this->relatedKey) => []];
        }

        if ($ids instanceof Collection) {
            $ids = $ids->all();
        }

        if (!is_array($ids)) {
            return [$ids => []];
        }

        $records = [];

        foreach ($ids as $key => $value) {
            if ($value instanceof Model) {
                $records[$value->getAttribute($this->relatedKey)] = [];
            } elseif (is_array($value)) {
                $records[$key] = $value;
            } else {
                $records[$value] = [];
            }
        }

        return $records;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getForeignPivotKeyName(): string
    {
        return $this->foreignPivotKey;
    }

    public function getRelatedPivotKeyName(): string
    {
        return $this->relatedPivotKey;
    }

    public function getRelationName(): string
    {
        return $this->relationName;
    }
}
