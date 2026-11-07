<?php

namespace App\Enums;

enum PlanFeature: string
{
    /** Banks reached through the open-banking aggregator (Enable Banking). */
    case BankAggregator = 'bank_aggregator';

    /** Providers we integrate with directly: brokers, exchanges and Wise. */
    case DirectIntegrations = 'direct_integrations';

    case AiSuggestions = 'ai_suggestions';
    case McpAccess = 'mcp_access';
    case ApiAccess = 'api_access';

    /**
     * Whether access to this feature is gated behind a paid (Pro) plan.
     */
    public function requiresProPlan(): bool
    {
        return match ($this) {
            self::BankAggregator, self::DirectIntegrations, self::AiSuggestions, self::McpAccess, self::ApiAccess => true,
        };
    }
}
