<?php

namespace App\Features;

use App\Models\User;

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
