<?php

namespace App\Services\Ai;

use App\Features\AiCategorization;
use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * The eligibility gate for AI auto-categorization: a hard config kill switch, a
 * pro subscription, an active (current-version) AI consent, and the per-user
 * Pennant rollout flag. All four must hold before any transaction is sent.
 */
class AiCategorizationGate
{
    public function allows(User $user): bool
    {
        if (! (bool) config('ai_categorization.enabled')) {
            return false;
        }

        if (! $user->hasProPlan()) {
            return false;
        }

        if (! $user->hasActiveAiConsent()) {
            return false;
        }

        return Feature::for($user)->active(AiCategorization::class);
    }
}
