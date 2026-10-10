<?php

namespace App\Features;

use App\Models\User;

/**
 * Shows the estimated next payment of a credit card (amount and date) on its
 * account page, computed from the statement dates the user sets on the card.
 *
 * @api
 */
class CreditCardStatements
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
