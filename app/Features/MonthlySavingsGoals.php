<?php

namespace App\Features;

use App\Models\User;

/**
 * Lets a user create monthly (recurring) savings goals. It gates creation
 * only: a monthly goal that already exists keeps working for its owner,
 * flag or not. Read it through `User::canCreateMonthlySavingsGoals()`.
 *
 * @api
 */
class MonthlySavingsGoals
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
