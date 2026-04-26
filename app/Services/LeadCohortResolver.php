<?php

namespace App\Services;

use App\Enums\LeadCohort;
use App\Models\UserLead;

class LeadCohortResolver
{
    private const FOUNDER_LIMIT = 10;

    private const EARLY_BIRD_LIMIT = 100;

    /**
     * Resolve the cohort for a lead based on current queue state.
     *
     * - Leads referenced as `referred_by_id` by any current founder
     *   become `FounderReferrer` regardless of their own rank.
     * - Otherwise rank is computed by `position` ascending, ignoring
     *   leads with `position` null or <= 0.
     */
    public function resolve(UserLead $lead): ?LeadCohort
    {
        if ($lead->position === null || $lead->position <= 0) {
            return null;
        }

        if ($this->isFounderReferrer($lead)) {
            return LeadCohort::FounderReferrer;
        }

        $rank = $this->rank($lead);

        return match (true) {
            $rank <= self::FOUNDER_LIMIT => LeadCohort::Founder,
            $rank <= self::EARLY_BIRD_LIMIT => LeadCohort::EarlyBird,
            default => LeadCohort::Waitlist,
        };
    }

    /**
     * 1-based queue rank. Assumes `position` > 0.
     */
    public function rank(UserLead $lead): int
    {
        return UserLead::query()
            ->whereNotNull('position')
            ->where('position', '>', 0)
            ->where('position', '<=', $lead->position)
            ->count();
    }

    /**
     * Whether the lead referred any of the current top-N founders.
     */
    public function isFounderReferrer(UserLead $lead): bool
    {
        $founderReferrerIds = $this->founderReferrerIds();

        return in_array($lead->id, $founderReferrerIds, true);
    }

    /**
     * IDs of leads that referred any current founder.
     *
     * @return list<string>
     */
    public function founderReferrerIds(): array
    {
        $founderIds = $this->founderIds();

        if ($founderIds === []) {
            return [];
        }

        return UserLead::query()
            ->whereIn('id', $founderIds)
            ->whereNotNull('referred_by_id')
            ->pluck('referred_by_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * IDs of the current top-N leads by position ascending.
     *
     * @return list<string>
     */
    public function founderIds(): array
    {
        return UserLead::query()
            ->whereNotNull('position')
            ->where('position', '>', 0)
            ->orderBy('position')
            ->limit(self::FOUNDER_LIMIT)
            ->pluck('id')
            ->all();
    }
}
