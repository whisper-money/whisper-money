<?php

namespace App\Policies;

use App\Models\RealEstateDetail;
use App\Models\User;
use App\Policies\Concerns\HandlesUserOwnership;

class RealEstateDetailPolicy
{
    use HandlesUserOwnership;

    /**
     * A real-estate detail has no space of its own — it inherits its account's,
     * so access follows membership of the account's space.
     */
    public function view(User $user, RealEstateDetail $realEstateDetail): bool
    {
        return $this->userCanAccess($user, $realEstateDetail->account);
    }

    public function update(User $user, RealEstateDetail $realEstateDetail): bool
    {
        return $this->userCanAccess($user, $realEstateDetail->account);
    }

    public function delete(User $user, RealEstateDetail $realEstateDetail): bool
    {
        return $this->userCanAccess($user, $realEstateDetail->account);
    }
}
