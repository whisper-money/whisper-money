<?php

namespace App\Policies;

use App\Models\RealEstateDetail;
use App\Models\User;

class RealEstateDetailPolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, RealEstateDetail $realEstateDetail): bool
    {
        return $user->id === $realEstateDetail->account->user_id;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, RealEstateDetail $realEstateDetail): bool
    {
        return $user->id === $realEstateDetail->account->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, RealEstateDetail $realEstateDetail): bool
    {
        return $user->id === $realEstateDetail->account->user_id;
    }
}
