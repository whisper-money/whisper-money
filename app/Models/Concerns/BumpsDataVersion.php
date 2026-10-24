<?php

namespace App\Models\Concerns;

use App\Services\Cache\DataVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        static::deleted($bump);
        static::updated(static function (Model $model) use ($bump): void {
            $bump($model);

            // A row moved to another user or space leaves the old one's view too.
            if ($model->isDirty(['user_id', 'space_id'])) {
                DataVersion::bumpOwner($model->getOriginal('user_id'), $model->getOriginal('space_id'));
            }
        });
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
     * The parent behind a belongs-to relation, trashed or not (a soft-deleted
     * account still owns the balances being removed with it), looked up once
     * per parent for the request or job.
     */
    protected function dataVersionOwnerVia(string $relation): ?Model
    {
        if ($this->relationLoaded($relation) && $this->getRelation($relation) !== null) {
            return $this->getRelation($relation);
        }

        /** @var BelongsTo<Model, $this> $parent */
        $parent = $this->{$relation}();
        $key = $parent->getRelated()->getTable().':'.$this->getAttribute($parent->getForeignKeyName());

        return DataVersion::ownerFor($key, fn (): ?Model => $parent->withoutGlobalScopes()->first());
    }
}
