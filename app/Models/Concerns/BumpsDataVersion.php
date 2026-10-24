<?php

namespace App\Models\Concerns;

use App\Services\Cache\DataVersion;
use Illuminate\Database\Eloquent\Model;

/**
 * Bumps the owning user's data version whenever a row is created, updated or
 * deleted (soft deletes, restores and force deletes included), so cached
 * widgets built from it are invalidated after the write commits.
 *
 * Only model events are seen: quiet saves, builder writes and upserts have to
 * call {@see DataVersion} themselves — `DataVersionGuardTest` keeps that list.
 */
trait BumpsDataVersion
{
    public static function bootBumpsDataVersion(): void
    {
        $bump = static fn (Model $model) => DataVersion::bumpOwnerOf($model->dataVersionOwner());

        static::created($bump);
        static::updated($bump);
        static::deleted($bump);
    }

    /**
     * The row carrying the `user_id` (and `space_id`) this record belongs to.
     * Rows without their own owner columns override it to point at a parent.
     */
    public function dataVersionOwner(): ?Model
    {
        return $this;
    }

    /**
     * The parent behind a belongs-to relation, trashed or not: a soft-deleted
     * account still owns the balances being removed with it.
     */
    protected function dataVersionOwnerVia(string $relation): ?Model
    {
        if ($this->relationLoaded($relation) && $this->getRelation($relation) !== null) {
            return $this->getRelation($relation);
        }

        return $this->{$relation}()->withoutGlobalScopes()->first();
    }
}
