<?php

namespace App\Features;

use App\Models\User;

/**
 * Opens the full import from another app to a user outside the window that
 * normally allows it (onboarding and the first days after it). Eligibility as a
 * whole lives in `User::canUseFullImport()`, never here.
 *
 * @api
 */
class FullImport
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
