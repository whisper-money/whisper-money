<?php

namespace App\Features;

use App\Models\User;

/**
 * Shows the custom dashboards UI: picking a dashboard, laying out widgets on
 * the grid and resizing them. It only hides the interface; the web routes and
 * MCP tools behind it are gated by plan, not by this flag. Off for everyone
 * until activated per user.
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
