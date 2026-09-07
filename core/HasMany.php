<?php

// $user->posts — every related row whose foreign key points at the parent.

class HasMany extends HasOneOrMany
{
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, new Collection());
        }

        return $models;
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        return $this->matchOneOrMany($models, $results, $relation, 'many');
    }

    public function getResults(): Collection
    {
        if ($this->getParentKey() === null) {
            return new Collection();
        }

        return $this->query->get();
    }
}
