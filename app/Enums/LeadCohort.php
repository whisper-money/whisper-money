<?php

namespace App\Enums;

enum LeadCohort: string
{
    case Founder = 'founder';
    case FounderReferrer = 'founder_referrer';
    case EarlyBird = 'early_bird';
    case Waitlist = 'waitlist';

    /**
     * Whether this cohort gets a single forever coupon
     * (same code on monthly + yearly).
     */
    public function isFounderTier(): bool
    {
        return $this === self::Founder || $this === self::FounderReferrer;
    }
}
