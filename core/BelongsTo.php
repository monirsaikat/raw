<?php

// $post->author — the parent row a foreign key on this model points at.

class BelongsTo extends Relation
{
    public function __construct(
        QueryBuilder $query,
        Model $child,
        protected string $foreignKey,
        protected string $ownerKey,
        protected string $relationName
    ) {
        parent::__construct($query, $child);
    }

    public function addConstraints(): void
    {
        $this->query->where($this->query->qualifyColumn($this->ownerKey), $this->parent->getAttribute($this->foreignKey));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn($this->query->qualifyColumn($this->ownerKey), $this->getKeys($models, $this->foreignKey));
    }

    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, null);
        }

        return $models;
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[(string) $result->getAttribute($this->ownerKey)] = $result;
        }

        foreach ($models as $model) {
            $key = (string) $model->getAttribute($this->foreignKey);

            if (isset($dictionary[$key])) {
                $model->setRelation($relation, $dictionary[$key]);
            }
        }

        return $models;
    }

    public function getResults(): ?Model
    {
        if ($this->parent->getAttribute($this->foreignKey) === null) {
            return null;
        }

        return $this->query->first();
    }

    public function getRelationExistenceQuery(QueryBuilder $query, QueryBuilder $parentQuery): QueryBuilder
    {
        return $query->whereColumn(
            $query->qualifyColumn($this->ownerKey),
            $parentQuery->qualifyColumn($this->foreignKey)
        );
    }

    // Sets the foreign key from a model (or key) without saving.
    public function associate(Model|int|string $model): Model
    {
        $this->parent->setAttribute($this->foreignKey, $model instanceof Model ? $model->getAttribute($this->ownerKey) : $model);

        if ($model instanceof Model) {
            $this->parent->setRelation($this->relationName, $model);
        } else {
            $this->parent->unsetRelation($this->relationName);
        }

        return $this->parent;
    }

    public function dissociate(): Model
    {
        $this->parent->setAttribute($this->foreignKey, null);

        return $this->parent->setRelation($this->relationName, null);
    }

    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    public function getOwnerKeyName(): string
    {
        return $this->ownerKey;
    }

    public function getRelationName(): string
    {
        return $this->relationName;
    }
}
