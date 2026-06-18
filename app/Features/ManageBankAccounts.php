<?php

namespace App\Features;

use App\Models\User;

/**
 * Gates the per-connection "Manage Accounts" surface while it is being rolled
 * out. For now it is limited to the admin user (ADMIN_EMAIL) so the flow can be
 * dogfooded before a wider release.
 *
 * @api
 */
class ManageBankAccounts
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return $user?->isAdmin() ?? false;
    }
}
