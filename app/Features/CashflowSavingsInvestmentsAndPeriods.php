<?php

namespace App\Features;

use App\Models\User;

/**
 * @api
 */
class CashflowSavingsInvestmentsAndPeriods
{
    /**
     * Resolve without touching Pennant storage.
     */
    public function before(?User $user): bool
    {
        return $user?->email === 'victoor89@gmail.com';
    }

    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
