<?php

// A column being added or changed in a Blueprint, with fluent modifiers:
//   $table->string('email', 150)->unique()->nullable()->after('name');

class ColumnDefinition
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public $default = null;
    public bool $unsigned = false;
    public bool $autoIncrement = false;
    public bool $primary = false;
    public bool|string $unique = false;
    public bool|string $index = false;
    public ?string $after = null;
    public bool $first = false;
    public ?string $comment = null;
    public bool $useCurrent = false;
    public bool $useCurrentOnUpdate = false;
    public bool $change = false;
    public ?string $charset = null;
    public ?string $collation = null;

    public function __construct(
        public string $type,
        public string $name,
        public array $parameters = [],
        protected ?Blueprint $blueprint = null
    ) {
    }

    public function nullable(bool $value = true): static
    {
        $this->nullable = $value;

        return $this;
    }

    public function default($value): static
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    public function unsigned(): static
    {
        $this->unsigned = true;

        return $this;
    }

    public function autoIncrement(): static
    {
        $this->autoIncrement = true;

        return $this;
    }

    public function primary(): static
    {
        $this->primary = true;

        return $this;
    }

    public function unique(?string $name = null): static
    {
        $this->unique = $name ?? true;

        return $this;
    }

    public function index(?string $name = null): static
    {
        $this->index = $name ?? true;

        return $this;
    }

    public function after(string $column): static
    {
        $this->after = $column;

        return $this;
    }

    public function first(): static
    {
        $this->first = true;

        return $this;
    }

    public function comment(string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function useCurrent(): static
    {
        $this->useCurrent = true;

        return $this;
    }

    public function useCurrentOnUpdate(): static
    {
        $this->useCurrentOnUpdate = true;

        return $this;
    }

    // Marks the column as MODIFY rather than ADD (Schema::table only).
    public function change(): static
    {
        $this->change = true;

        return $this;
    }

    public function charset(string $charset): static
    {
        $this->charset = $charset;

        return $this;
    }

    public function collation(string $collation): static
    {
        $this->collation = $collation;

        return $this;
    }

    // Foreign key: foreignId('user_id')->constrained() → REFERENCES users(id).
    public function constrained(?string $table = null, string $column = 'id'): ForeignKeyDefinition
    {
        $table ??= str_plural(preg_replace('/_' . preg_quote($column, '/') . '$/', '', $this->name));

        return $this->references($column)->on($table);
    }

    public function references(string|array $columns): ForeignKeyDefinition
    {
        if ($this->blueprint === null) {
            throw new LogicException('references() needs a Blueprint.');
        }

        return $this->blueprint->foreign($this->name)->references($columns);
    }
}
