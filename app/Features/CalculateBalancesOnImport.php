<?php

namespace App\Features;

use App\Models\User;

/**
 * @api
 */
class CalculateBalancesOnImport
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
