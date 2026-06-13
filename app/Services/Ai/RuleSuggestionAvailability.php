<?php

namespace App\Services\Ai;

use App\Enums\SuggestionRunStatus;
use App\Models\SuggestionRun;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Answers "can this user generate suggestions right now?" — eligibility (enough
 * data) and the once-a-month throttle (only successful runs count).
 */
class RuleSuggestionAvailability
{
    public function transactionCount(User $user): int
    {
        return $user->transactions()->count();
    }

    public function minTransactions(): int
    {
        return (int) config('ai_suggestions.eligibility_min_transactions');
    }

    public function isEligible(User $user): bool
    {
        return $this->transactionCount($user) >= $this->minTransactions();
    }

    /**
     * The most recent run that produced usable suggestions.
     */
    public function latestSuccessfulRun(User $user): ?SuggestionRun
    {
        return $user->suggestionRuns()
            ->where('status', SuggestionRunStatus::Completed)
            ->latest()
            ->first();
    }

    public function latestRun(User $user): ?SuggestionRun
    {
        return $user->suggestionRuns()->latest()->first();
    }

    /**
     * The moment the throttle lifts, or null if the user may generate now.
     */
    public function throttledUntil(User $user): ?CarbonImmutable
    {
        $run = $this->latestSuccessfulRun($user);

        if ($run === null) {
            return null;
        }

        $until = CarbonImmutable::parse($run->created_at)
            ->addDays((int) config('ai_suggestions.throttle_days'));

        return $until->isFuture() ? $until : null;
    }

    public function isThrottled(User $user): bool
    {
        return $this->throttledUntil($user) !== null;
    }
}
