<?php

// Base class for hasOne/hasMany/belongsTo/belongsToMany. A relation wraps a
// query on the related model constrained to one parent (lazy loading) or to
// a whole set of parents (eager loading). Unknown methods forward to the
// query, so $user->posts()->where('published', 1)->get() works.

abstract class Relation
{
    protected static bool $constraints = true;

    protected QueryBuilder $query;
    protected Model $parent;
    protected string $related;

    public function __construct(QueryBuilder $query, Model $parent)
    {
        $this->query = $query;
        $this->parent = $parent;
        $this->related = (string) $query->getModel();

        if (static::$constraints) {
            $this->addConstraints();
        }
    }

    // Builds relations without the per-parent constraint (eager loading,
    // whereHas) — the caller adds its own.
    public static function noConstraints(callable $callback)
    {
        $previous = static::$constraints;
        static::$constraints = false;

        try {
            return $callback();
        } finally {
            static::$constraints = $previous;
        }
    }

    // Constrain the query to the single parent model.
    abstract public function addConstraints(): void;

    // Constrain the query to all the given parent models at once.
    abstract public function addEagerConstraints(array $models): void;

    // Give every parent an empty relation value (null or empty collection).
    abstract public function initRelation(array $models, string $relation): array;

    // Distribute the eagerly loaded results onto their parents.
    abstract public function match(array $models, Collection $results, string $relation): array;

    // The lazy-loaded value for the single parent.
    abstract public function getResults();

    // A query on the related table correlated to the outer query, used by
    // whereHas()/has()/withCount().
    abstract public function getRelationExistenceQuery(QueryBuilder $query, QueryBuilder $parentQuery): QueryBuilder;

    public function getRelationExistenceCountQuery(QueryBuilder $query, QueryBuilder $parentQuery): QueryBuilder
    {
        return $this->getRelationExistenceQuery($query, $parentQuery)->select(new Raw('COUNT(*)'));
    }

    public function getEager(): Collection
    {
        return $this->get();
    }

    public function get(): Collection
    {
        return $this->query->get();
    }

    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    public function getParent(): Model
    {
        return $this->parent;
    }

    public function getRelated(): string
    {
        return $this->related;
    }

    protected function getKeys(array $models, string $key): array
    {
        $keys = [];

        foreach ($models as $model) {
            $value = $model->getAttribute($key);

            if ($value !== null) {
                $keys[] = $value;
            }
        }

        return array_values(array_unique($keys));
    }

    public function __call(string $method, array $arguments)
    {
        $result = $this->query->$method(...$arguments);

        return $result === $this->query ? $this : $result;
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }
}
