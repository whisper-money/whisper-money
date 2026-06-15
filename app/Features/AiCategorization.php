<?php

namespace App\Features;

use App\Models\User;

/**
 * Gates AI auto-categorization of transactions. Rolled out gradually to
 * pro + AI-consented users; off by default until explicitly enabled.
 *
 * @api
 */
class AiCategorization
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
