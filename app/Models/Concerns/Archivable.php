<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Archiving a budget or a savings goal is one-way: it records the day it
 * happened, the record turns read-only, and it stops taking in new data from
 * then on. Everything it already counted stays exactly as it was, which is why
 * the archived record remains reachable instead of being deleted.
 *
 * Accounts have the same column but can be brought back, so they keep their own
 * copy of these helpers rather than sharing this trait.
 */
trait Archivable
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
