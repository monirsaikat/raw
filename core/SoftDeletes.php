<?php

// Soft deletes: delete() stamps deleted_at instead of removing the row, and
// a global scope hides stamped rows from every query.
//
//   class Post extends Model { use SoftDeletes; }
//   Post::withTrashed()->get();  Post::onlyTrashed()->get();
//   $post->trashed();  $post->restore();  $post->forceDelete();
//
// The table needs a nullable deleted_at column ($table->softDeletes()).

trait SoftDeletes
{
    protected bool $forceDeleting = false;

    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope('soft_deletes', function (QueryBuilder $query): void {
            $query->whereNull($query->qualifyColumn(static::getDeletedAtColumn()));
        });
    }

    public static function getDeletedAtColumn(): string
    {
        return defined('static::DELETED_AT') ? static::DELETED_AT : 'deleted_at';
    }

    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        if (!$this->fireEvent('forceDeleting')) {
            return false;
        }

        $this->forceDeleting = true;

        try {
            $deleted = $this->delete();
        } finally {
            $this->forceDeleting = false;
        }

        if ($deleted) {
            $this->fireEvent('forceDeleted');
        }

        return $deleted;
    }

    protected function performDeleteOnModel(): void
    {
        if ($this->forceDeleting) {
            static::newQueryWithoutScopes()->where(static::primaryKey(), $this->getKey())->forceDelete();
            $this->exists = false;

            return;
        }

        $time = $this->freshTimestamp();
        $columns = [static::getDeletedAtColumn() => $time];

        if (static::usesTimestamps() && static::UPDATED_AT !== '') {
            $columns[static::UPDATED_AT] = $time;
        }

        static::newQueryWithoutScopes()->where(static::primaryKey(), $this->getKey())->update($columns);

        foreach ($columns as $column => $value) {
            $this->setRawAttribute($column, $value);
        }

        $this->syncOriginal();
    }

    public function restore(): bool
    {
        if (!$this->fireEvent('restoring')) {
            return false;
        }

        $this->setRawAttribute(static::getDeletedAtColumn(), null);
        $this->exists = true;

        $result = $this->save();

        $this->fireEvent('restored');

        return $result;
    }

    public function trashed(): bool
    {
        return $this->getRawAttribute(static::getDeletedAtColumn()) !== null;
    }
}
