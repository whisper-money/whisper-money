<?php

namespace App\Enums;

/**
 * What the cancel flow puts in front of a user before they confirm, matched
 * to the reason they gave (see `App\Services\Subscriptions\CancellationOffers`).
 *
 * The first two keep the plan running on different terms, and taking one ends
 * the flow as saved. The last two only point at help: the user may still go on
 * and cancel.
 */
enum CancellationOffer: string
{
    case RetentionCoupon = 'retention_coupon';

    case SwitchToYearly = 'switch_to_yearly';

    case Roadmap = 'roadmap';

    case Support = 'support';
}
