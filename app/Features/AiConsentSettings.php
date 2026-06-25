<?php

namespace App\Features;

use App\Models\User;

/**
 * @api
 */
class AiConsentSettings
{
    /**
     * Resolve the feature's initial value.
     *
     * Off by default; enable per user with
     * `php artisan feature:enable AiConsentSettings user@example.com`
     * to surface AI consent management outside of onboarding (billing
     * settings toggle + transactions banner).
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
