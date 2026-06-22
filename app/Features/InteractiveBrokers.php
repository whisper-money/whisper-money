<?php

namespace App\Features;

use App\Models\User;

/**
 * @api
 */
class InteractiveBrokers
{
    /**
     * Resolve the feature's initial value.
     *
     * Off by default; enable per beta tester with
     * `php artisan feature:enable InteractiveBrokers user@example.com`
     * until the Flex integration is validated against a live account.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
