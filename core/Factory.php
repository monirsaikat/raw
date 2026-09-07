<?php

// Model factories for seeding and tests. database/factories/UserFactory.php:
//
//   class UserFactory extends Factory
//   {
//       public function definition(): array
//       {
//           return ['name' => fake()->name(), 'email' => fake()->unique()->safeEmail(), ...];
//       }
//   }
//
//   User::factory()->count(5)->create();
//   User::factory()->state(['role' => 'admin'])->make();
//   Post::factory()->create(['user_id' => User::factory()]);   // nested factory → key

abstract class Factory
{
    protected string $model = '';
    protected int $count = 1;
    protected array $states = [];
    protected array $afterMaking = [];
    protected array $afterCreating = [];

    abstract public function definition(): array;

    public static function new(array $attributes = []): static
    {
        $factory = new static();

        return $attributes === [] ? $factory : $factory->state($attributes);
    }

    // Resolves "{Model}Factory" from database/factories.
    public static function factoryForModel(string $model): static
    {
        $class = class_basename($model) . 'Factory';

        if (!class_exists($class)) {
            $file = BASE_PATH . '/database/factories/' . $class . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        }

        if (!class_exists($class)) {
            throw new RuntimeException("Factory [$class] not found. Create database/factories/$class.php or run make:factory.");
        }

        $factory = new $class();

        if ($factory->model === '') {
            $factory->model = $model;
        }

        return $factory;
    }

    public function modelName(): string
    {
        if ($this->model !== '') {
            return $this->model;
        }

        return substr(class_basename(static::class), 0, -7);
    }

    public function count(int $count): static
    {
        $clone = clone $this;
        $clone->count = max(0, $count);

        return $clone;
    }

    // state(['active' => 1]) or state(fn (array $attributes) => [...])
    public function state(array|callable $state): static
    {
        $clone = clone $this;
        $clone->states[] = $state;

        return $clone;
    }

    // Cycles through the given attribute sets: sequence(['role' => 'a'], ['role' => 'b'])
    public function sequence(array ...$sequence): static
    {
        $index = 0;

        return $this->state(function () use (&$index, $sequence): array {
            return $sequence[$index++ % count($sequence)];
        });
    }

    public function afterMaking(callable $callback): static
    {
        $clone = clone $this;
        $clone->afterMaking[] = $callback;

        return $clone;
    }

    public function afterCreating(callable $callback): static
    {
        $clone = clone $this;
        $clone->afterCreating[] = $callback;

        return $clone;
    }

    // One resolved attribute set, without building a model.
    public function raw(array $attributes = []): array
    {
        $result = $this->definition();

        foreach ($this->states as $state) {
            $result = array_merge($result, is_callable($state) ? $state($result) : $state);
        }

        return $this->resolveAttributes(array_merge($result, $attributes));
    }

    // Unsaved model(s): a Model for count 1, a Collection otherwise.
    public function make(array $attributes = [])
    {
        if ($this->count === 1) {
            return $this->makeInstance($attributes);
        }

        return Collection::times($this->count, fn () => $this->makeInstance($attributes));
    }

    public function makeOne(array $attributes = []): Model
    {
        return $this->makeInstance($attributes);
    }

    // Saved model(s).
    public function create(array $attributes = [])
    {
        $made = $this->make($attributes);
        $models = $made instanceof Collection ? $made : new Collection([$made]);

        foreach ($models as $model) {
            $model->save();

            foreach ($this->afterCreating as $callback) {
                $callback($model);
            }
        }

        return $made;
    }

    public function createOne(array $attributes = []): Model
    {
        return $this->count(1)->create($attributes);
    }

    protected function makeInstance(array $attributes): Model
    {
        $class = $this->modelName();
        $model = new $class();
        $model->forceFill($this->raw($attributes));

        foreach ($this->afterMaking as $callback) {
            $callback($model);
        }

        return $model;
    }

    // Closures receive the attributes so far; nested factories and models
    // collapse to their primary key (creating the factory's model first).
    protected function resolveAttributes(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if ($value instanceof Closure) {
                $value = $value($attributes);
            }

            if ($value instanceof Factory) {
                $value = $value->createOne();
            }

            if ($value instanceof Model) {
                $value = $value->getKey();
            }

            $attributes[$key] = $value;
        }

        return $attributes;
    }
}
