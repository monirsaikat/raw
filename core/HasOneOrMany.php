<?php

abstract class HasOneOrMany extends Relation
{
    public function __construct(QueryBuilder $query, Model $parent, protected string $foreignKey, protected string $localKey)
    {
        parent::__construct($query, $parent);
    }

    public function addConstraints(): void
    {
        $this->query
            ->where($this->qualifiedForeignKey(), $this->getParentKey())
            ->whereNotNull($this->qualifiedForeignKey());
    }

    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn($this->qualifiedForeignKey(), $this->getKeys($models, $this->localKey));
    }

    protected function matchOneOrMany(array $models, Collection $results, string $relation, string $type): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[(string) $result->getAttribute($this->getForeignKeyName())][] = $result;
        }

        foreach ($models as $model) {
            $key = (string) $model->getAttribute($this->localKey);

            if (isset($dictionary[$key])) {
                $model->setRelation($relation, $type === 'one' ? $dictionary[$key][0] : new Collection($dictionary[$key]));
            }
        }

        return $models;
    }

    public function getRelationExistenceQuery(QueryBuilder $query, QueryBuilder $parentQuery): QueryBuilder
    {
        return $query->whereColumn(
            $query->qualifyColumn($this->getForeignKeyName()),
            $parentQuery->qualifyColumn($this->localKey)
        );
    }

    public function getParentKey()
    {
        return $this->parent->getAttribute($this->localKey);
    }

    public function getForeignKeyName(): string
    {
        $segments = explode('.', $this->foreignKey);

        return end($segments);
    }

    public function getLocalKeyName(): string
    {
        return $this->localKey;
    }

    public function qualifiedForeignKey(): string
    {
        return $this->query->qualifyColumn($this->foreignKey);
    }

    // ------------------------------------------------------------ writing --

    // A new related instance with the foreign key set, not yet saved.
    public function make(array $attributes = []): Model
    {
        $instance = new ($this->related)($attributes);
        $instance->setAttribute($this->getForeignKeyName(), $this->getParentKey());

        return $instance;
    }

    public function create(array $attributes = []): Model
    {
        $instance = $this->make($attributes);
        $instance->save();

        return $instance;
    }

    public function createMany(array $records): Collection
    {
        return new Collection(array_map(fn (array $record) => $this->create($record), $records));
    }

    public function save(Model $model): Model
    {
        $model->setAttribute($this->getForeignKeyName(), $this->getParentKey());
        $model->save();

        return $model;
    }

    public function saveMany(iterable $models): Collection
    {
        $saved = [];

        foreach ($models as $model) {
            $saved[] = $this->save($model);
        }

        return new Collection($saved);
    }

    public function firstOrCreate(array $attributes, array $values = []): Model
    {
        return (clone $this->query)->where($attributes)->first() ?? $this->create(array_merge($attributes, $values));
    }

    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        $instance = (clone $this->query)->where($attributes)->first();

        if ($instance === null) {
            return $this->create(array_merge($attributes, $values));
        }

        $instance->fill($values)->save();

        return $instance;
    }
}
