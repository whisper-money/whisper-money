<?php

namespace App\Features;

use App\Models\User;

/**
 * Gates the monthly summary email, its shareable cards and the history screen
 * while it is being rolled out. Off by default: through September it is enabled
 * for the founders and the demo/press accounts only, rehearsing on August's real
 * data, and turned on for everyone in time for the October 3rd send.
 *
 * Toggle with `php artisan feature:enable App\\Features\\MonthlySummaries <target>`.
 *
 * @api
 */
class MonthlySummaries
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
