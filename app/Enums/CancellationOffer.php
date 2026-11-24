<?php

namespace App\Enums;

/**
 * What the cancel flow puts in front of a user before they confirm, matched
 * to the reason they gave (see `App\Services\Subscriptions\CancellationOffers`).
 *
 * The first four keep the subscription on different terms (a discount, the
 * yearly price, a few months' pause, a longer trial), and taking one ends the
 * flow as saved.
 * Support only points at help: the user may still go on and cancel.
 */
enum CancellationOffer: string
{
    case RetentionCoupon = 'retention_coupon';

    case SwitchToYearly = 'switch_to_yearly';

    case Pause = 'pause';

    case ExtendTrial = 'extend_trial';

    /**
     * No longer offered: the in-app roadmap page is gone. Kept because
     * `CancellationSurveyResponse` casts `offer_shown` and `offer_accepted`
     * to this enum, and answers recorded before then hold 'roadmap'.
     */
    case Roadmap = 'roadmap';

    case Support = 'support';
}
