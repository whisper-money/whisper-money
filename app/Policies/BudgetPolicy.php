<?php

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;
use App\Policies\Concerns\HandlesUserOwnership;

class BudgetPolicy
{
    use HandlesUserOwnership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Budget $budget): bool
    {
        return $this->userCanAccess($user, $budget);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function restore(User $user, Budget $budget): bool
    {
        return $this->userCanAccess($user, $budget);
    }

    public function forceDelete(User $user, Budget $budget): bool
    {
        return $this->userCanAccess($user, $budget);
    }
}
