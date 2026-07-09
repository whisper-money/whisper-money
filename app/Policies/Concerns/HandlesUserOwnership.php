<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait HandlesUserOwnership
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Model $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Model $model): bool
    {
        return $this->userCanAccess($user, $model);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Model $model): bool
    {
        return $this->userCanAccess($user, $model);
    }

    /**
     * A space-owned model is accessible to any member of its space; models that
     * predate spaces (or child records without a space_id) fall back to the
     * legacy creator check.
     */
    protected function userCanAccess(User $user, Model $model): bool
    {
        $spaceId = $model->getAttribute('space_id');

        if ($spaceId === null) {
            return $user->id === $model->getAttribute('user_id');
        }

        // Authorize purely on live membership — never on current_space_id, which
        // can still point at a space the user was just removed from.
        return $user->accessibleSpaces()->contains('id', $spaceId);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }
}
