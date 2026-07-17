<?php

namespace App\Features;

use App\Models\User;

/**
 * Gates the MCP access settings screen while the feature is being rolled out.
 * Toggle per user / everyone with `php artisan feature:enable App\\Features\\Mcp <target>`.
 *
 * @api
 */
class Mcp
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
