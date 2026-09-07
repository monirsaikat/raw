<?php

// Adds $user->can('update', $post) / cannot() / canAny() to a user model.

trait Authorizable
{
    public function can(string $ability, mixed ...$arguments): bool
    {
        return gate()->forUser($this)->allows($ability, ...$arguments);
    }

    public function cannot(string $ability, mixed ...$arguments): bool
    {
        return !$this->can($ability, ...$arguments);
    }

    public function canAny(array $abilities, mixed ...$arguments): bool
    {
        return gate()->forUser($this)->any($abilities, ...$arguments);
    }
}
