<?php

namespace App\Features;

use App\Models\User;

/**
 * Gates the visible spaces UI (the switcher and space management). The
 * space-scoping of data is always on and safe; this flag only controls whether
 * a user can see and manage more than their invisible personal space. Defaults
 * to admins for internal dogfooding; flipped to the Business plan at launch.
 *
 * @api
 */
class Spaces
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return $user?->isAdmin() ?? false;
    }
}
