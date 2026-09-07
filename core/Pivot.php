<?php

// The pivot-table row behind a belongsToMany link: $tag->pivot->created_at.

class Pivot extends Model
{
    protected static bool $timestamps = false;
    protected static array $guarded = [];

    public function __construct(private string $pivotTable = '', array $attributes = [])
    {
        parent::__construct();

        $this->attributes = $attributes;
        $this->original = $attributes;
        $this->exists = true;
    }

    public function getPivotTable(): string
    {
        return $this->pivotTable;
    }
}
