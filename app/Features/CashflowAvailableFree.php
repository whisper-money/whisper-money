<?php

namespace App\Features;

use App\Models\User;

/**
 * Shows the "Available" reading of the cashflow page: the income left once
 * expenses and what was saved or invested are taken out, plus the savings and
 * investments split. Off for everyone until activated per user.
 *
 * @api
 */
class CashflowAvailableFree
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
