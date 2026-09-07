<?php

// User::factory()->count(10)->create();  User::factory()->create(['email' => 'a@b.c']);

class UserFactory extends Factory
{
    protected string $model = 'User';

    public function definition(): array
    {
        // Hashing is slow; do it once per process for all factory users.
        static $password = null;

        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => $password ??= User::hashPassword('password'),
        ];
    }
}
