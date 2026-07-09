<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Policies\Concerns\HandlesUserOwnership;

class AccountPolicy
{
    use HandlesUserOwnership;

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Account $account): bool
    {
        return $this->userCanAccess($user, $account);
    }
}
