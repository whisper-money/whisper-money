<?php

namespace App\Actions\Subscription;

use App\Services\Subscriptions\StripePriceIds;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;

/**
 * Moves a monthly subscriber onto the yearly plan of their tier, the cancel
 * flow's fallback for "too expensive". As with `SwapLatamToStandard`, the
 * change is invoiced right away: a paid month is credited against the year
 * charged today, and a trial keeps its end date and pays nothing until then.
 */
class SwitchToYearly
{
    public function __construct(private StripePriceIds $priceIds) {}

    /**
     * @param  array<string, mixed>  $yearlyPlan  What `CancellationOffers::yearlyPlanFor()` returned.
     *
     * @throws IncompletePayment When the card needs confirming.
     */
    public function handle(Subscription $subscription, array $yearlyPlan): void
    {
        $subscription->swapAndInvoice(
            $this->priceIds->forLookupKey((string) $yearlyPlan['stripe_lookup_key']),
        );
    }
}
