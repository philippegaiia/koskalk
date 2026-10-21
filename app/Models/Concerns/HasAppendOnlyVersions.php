<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Versioned reference data: rows are never edited or deleted, only superseded by a newer version.
 * Tables need `version`, `is_current`, `superseded_at` and `published_by_user_id` columns.
 */
trait HasAppendOnlyVersions
{
    /**
     * Columns that identify one versioned series (empty for a single global series).
     *
     * @return list<string>
     */
    abstract public static function versionScopeColumns(): array;

    protected static function bootHasAppendOnlyVersions(): void
    {
        static::updating(function (Model $model): void {
            $changed = array_diff(array_keys($model->getDirty()), ['is_current', 'superseded_at', 'updated_at']);

            if ($changed !== []) {
                throw new LogicException(class_basename($model).' versions are append-only; publish a new version instead.');
            }
        });

        static::deleting(function (Model $model): void {
            throw new LogicException(class_basename($model).' versions cannot be deleted.');
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }
}
