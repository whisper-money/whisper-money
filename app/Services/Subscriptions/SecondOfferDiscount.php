<?php

namespace App\Services\Subscriptions;

use App\Enums\UpsellSource;
use App\Models\User;

/**
 * The first-period discount the second offer carries on top of its free trial
 * (`subscriptions.second_offer_coupon`): what the dialog quotes, and which
 * checkout actually gets it.
 *
 * Both read the same config, so the saving the dialog prints is the one Stripe
 * applies. Whether a checkout gets it is decided here, from the user's own
 * record, never from anything the browser sends beyond the upsell point.
 */
class SecondOfferDiscount
{
    /**
     * The saving to quote, or null when no coupon is configured (the dialog
     * then sells the plan at its full price, as the checkout charges it).
     *
     * @return array{percentOff: int, months: int}|null
     */
    public function quote(): ?array
    {
        if (blank(config('subscriptions.second_offer_coupon.id'))) {
            return null;
        }

        return [
            'percentOff' => (int) config('subscriptions.second_offer_coupon.percent_off'),
            'months' => (int) config('subscriptions.second_offer_coupon.duration_in_months'),
        ];
    }

    /**
     * The Stripe coupon a checkout started from `$source` gets, or null for the
     * normal checkout.
     *
     * Only the second offer's own checkout, at the standard price (the latam
     * price is already the regional discount), for a user who was shown the
     * dialog within the eligibility window and has never had a subscription:
     * the discount is a nudge for someone deciding now, not a code to come back
     * for, and never for a former subscriber.
     */
    public function couponFor(User $user, ?UpsellSource $source, bool $isLatam): ?string
    {
        if ($source !== UpsellSource::SecondOffer || $isLatam || $this->quote() === null) {
            return null;
        }

        $shownAt = $user->second_offer_shown_at;
        $eligibleDays = (int) config('subscriptions.second_offer_coupon.eligible_days');

        if ($shownAt === null || $shownAt->lt(now()->subDays($eligibleDays))) {
            return null;
        }

        if ($user->subscriptions()->exists()) {
            return null;
        }

        return (string) config('subscriptions.second_offer_coupon.id');
    }
}
