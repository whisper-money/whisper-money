<?php

namespace App\Features;

use App\Models\User;

/**
 * Shows the custom dashboards UI: picking a dashboard, laying out widgets on
 * the grid and resizing them. It only hides the interface; the web routes and
 * MCP tools behind it are gated by plan, not by this flag. Off for everyone
 * until activated per user.
 *
 * Guests read it with the null scope, which decides whether the landing shows
 * its custom dashboards block. To show it to them, activate that scope:
 * `Feature::for(null)->activate(CustomDashboards::class)`.
 * `activateForEveryone()` only rewrites scopes already stored, so it reaches
 * guests only once one has visited since the flag was defined.
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
