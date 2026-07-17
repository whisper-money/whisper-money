<?php

namespace App\Enums;

enum PlanFeature: string
{
    case ConnectedAccounts = 'connected_accounts';
    case AiSuggestions = 'ai_suggestions';
    case McpAccess = 'mcp_access';

    /**
     * Whether access to this feature is gated behind a paid (Pro) plan.
     */
    public function requiresProPlan(): bool
    {
        return match ($this) {
            self::ConnectedAccounts, self::AiSuggestions, self::McpAccess => true,
        };
    }
}
