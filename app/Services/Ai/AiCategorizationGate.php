<?php

namespace App\Services\Ai;

use App\Models\User;

/**
 * The eligibility gate for AI auto-categorization: a hard config kill switch, a
 * pro subscription and an active (current-version) AI consent. All three must
 * hold before any transaction is sent.
 */
class AiCategorizationGate
{
    public function allows(User $user): bool
    {
        return (bool) config('ai_categorization.enabled')
            && $user->hasProPlan()
            && $user->hasActiveAiConsent();
    }
}
