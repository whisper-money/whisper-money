<?php

namespace App\Features;

use App\Models\User;

/**
 * Custom dashboards: picking a dashboard, laying out widgets on the grid and
 * resizing them. This instance has no plans, so the flag is the only gate:
 * it shows the interface and opens the routes behind it. Off for everyone
 * until activated per user (`php artisan feature:enable CustomDashboards
 * <email>`).
 *
 * @api
 */
class CustomDashboards
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
