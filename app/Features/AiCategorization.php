<?php

namespace App\Features;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Gates AI auto-categorization of transactions. Rolled out to users who signed
 * up after `ai_categorization.rollout_after`; combined with the pro plan and
 * AI consent checks in AiCategorizationGate.
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
        if ($user === null) {
            return false;
        }

        $rolloutAfter = config('ai_categorization.rollout_after');

        if (blank($rolloutAfter)) {
            return false;
        }

        return $user->created_at !== null
            && $user->created_at->gt(Carbon::parse($rolloutAfter));
    }
}
