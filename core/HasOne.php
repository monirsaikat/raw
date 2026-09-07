<?php

// $user->profile — one related row whose foreign key points at the parent.

class HasOne extends HasOneOrMany
{
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, null);
        }

        return $models;
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        return $this->matchOneOrMany($models, $results, $relation, 'one');
    }

    public function getResults(): ?Model
    {
        if ($this->getParentKey() === null) {
            return null;
        }

        return $this->query->first();
    }
}
