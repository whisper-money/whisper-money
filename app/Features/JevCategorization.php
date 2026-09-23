<?php

namespace App\Features;

use App\Models\User;

/**
 * Routes the user's AI transaction categorization to TypeSafe AI's Jev instead
 * of the default provider, so the two can be compared in production. Rolled
 * out with `feature:enable JevCategorization <email|N%|all>`.
 *
 * @api
 */
class JevCategorization
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
