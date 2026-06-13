<?php

namespace App\Enums;

enum PlanFeature: string
{
    case ConnectedAccounts = 'connected_accounts';
    case AiSuggestions = 'ai_suggestions';

    /**
     * Whether access to this feature is gated behind a paid (Pro) plan.
     */
    public function requiresProPlan(): bool
    {
        return match ($this) {
            self::ConnectedAccounts, self::AiSuggestions => true,
        };
    }
}
