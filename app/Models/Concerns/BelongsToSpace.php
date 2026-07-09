<?php

namespace App\Models\Concerns;

use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as belonging to a Space (the tenant). On create, `space_id` is
 * filled from the row's own `user_id` (its owner's current space) unless it was
 * set explicitly — so factories and existing single-owner creation paths keep
 * working untouched, while multi-space callers pass `space_id` themselves.
 *
 * @property ?string $space_id
 */
trait BelongsToSpace
{
    public static function bootBelongsToSpace(): void
    {
        static::creating(function ($model): void {
            if ($model->space_id === null) {
                $model->space_id = $model->resolveDefaultSpaceId();
            }
        });
    }

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * Restrict a query to a single space.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForSpace(Builder $query, Space|string $space): Builder
    {
        return $query->where($this->getTable().'.space_id', $space instanceof Space ? $space->id : $space);
    }

    /**
     * The space a new row defaults to when none was set explicitly: the current
     * space of the user that owns the row. Models with a stronger anchor (e.g. a
     * transaction inheriting its account's space) override this.
     */
    protected function resolveDefaultSpaceId(): ?string
    {
        return $this->spaceIdFromUser();
    }

    /**
     * The current space of the user that owns this row, if any.
     */
    protected function spaceIdFromUser(): ?string
    {
        $userId = $this->getAttribute('user_id');

        if ($userId === null) {
            return null;
        }

        return User::query()->whereKey($userId)->value('current_space_id');
    }
}
