<?php

namespace App\Features;

use App\Models\User;

/**
 * Gates the AI-powered automation-rule suggestions feature.
 *
 * @api
 */
class AiRuleSuggestions
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
