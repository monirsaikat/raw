<?php

// A foreign-key constraint being added to a Blueprint:
//   $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

class ForeignKeyDefinition
{
    public array $columns;
    public array $references = [];
    public ?string $on = null;
    public ?string $onDelete = null;
    public ?string $onUpdate = null;
    public ?string $name = null;

    public function __construct(string|array $columns, ?string $name = null)
    {
        $this->columns = (array) $columns;
        $this->name = $name;
    }

    public function references(string|array $columns): static
    {
        $this->references = (array) $columns;

        return $this;
    }

    public function on(string $table): static
    {
        $this->on = $table;

        return $this;
    }

    public function onDelete(string $action): static
    {
        $this->onDelete = strtoupper($action);

        return $this;
    }

    public function onUpdate(string $action): static
    {
        $this->onUpdate = strtoupper($action);

        return $this;
    }

    public function cascadeOnDelete(): static
    {
        return $this->onDelete('cascade');
    }

    public function nullOnDelete(): static
    {
        return $this->onDelete('set null');
    }

    public function restrictOnDelete(): static
    {
        return $this->onDelete('restrict');
    }

    public function noActionOnDelete(): static
    {
        return $this->onDelete('no action');
    }

    public function cascadeOnUpdate(): static
    {
        return $this->onUpdate('cascade');
    }

    public function restrictOnUpdate(): static
    {
        return $this->onUpdate('restrict');
    }

    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }
}
