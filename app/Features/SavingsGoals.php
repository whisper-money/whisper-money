<?php

namespace App\Features;

use App\Models\User;

/**
 * Gates the savings goals feature while it is being rolled out.
 * Toggle per user / everyone with `php artisan feature:enable App\\Features\\SavingsGoals <target>`.
 *
 * @api
 */
class SavingsGoals
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
