<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Forecast rows are only ever reached by the user who owns them, also inside a
 * shared space. Each policy names where its row's owner lives: on the row
 * itself, or on the forecast line, account or transaction it hangs off.
 */
trait HandlesForecastOwnership
{
    abstract protected function ownerId(Model $model): ?string;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    private function owns(User $user, Model $model): bool
    {
        return $user->id === $this->ownerId($model);
    }
}
