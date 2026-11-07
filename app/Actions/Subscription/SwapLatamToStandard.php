<?php

namespace App\Actions\Subscription;

use App\Models\User;
use App\Services\Subscriptions\StripePriceIds;
use App\Services\Subscriptions\SubscribedPlan;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;

/**
 * Moves a subscriber off the regional latam price onto the standard one of the
 * same billing period, which is the only way a latam subscriber gets bank
 * connections. The difference for the rest of the period is invoiced and
 * charged right away rather than deferred to the next renewal: on a yearly
 * plan that renewal can be eleven months off.
 *
 * Only ever towards the standard price. Nothing offers the opposite move, and
 * a subscription that is not on latam has no standard plan to go to here.
 *
 * It works whether or not `subscriptions.latam_pricing` is on: the switch only
 * decides who is *offered* latam, and someone already paying it must always be
 * able to leave it.
 */
class SwapLatamToStandard
{
    public function __construct(
        private SubscribedPlan $subscribedPlan,
        private StripePriceIds $priceIds,
    ) {}

    /**
     * The standard plan a subscription would be swapped to, or null when it
     * cannot be swapped: the account must never reach Stripe (a seeded one),
     * it is not paying the latam price, it is cancelled or waiting on a payment
     * (a swap would silently resume the first and Stripe refuses the second),
     * or no standard plan bills over the same period.
     *
     * @return array<string, mixed>|null
     */
    public function standardPlanFor(User $user, ?Subscription $subscription): ?array
    {
        if (
            $subscription === null
            || $user->cannotUseStripe()
            || ! $subscription->valid()
            || $subscription->onGracePeriod()
            || $subscription->hasIncompletePayment()
            || ! $this->subscribedPlan->isLatam($user, $subscription)
        ) {
            return null;
        }

        $billingPeriod = $this->subscribedPlan->for($user, $subscription)['billing_period'] ?? null;

        return collect(config('subscriptions.plans', []))
            ->first(fn (array $plan): bool => $plan['billing_period'] === $billingPeriod);
    }

    /**
     * @param  array<string, mixed>  $standardPlan  What `standardPlanFor()` returned.
     *
     * @throws IncompletePayment When the card needs confirming.
     */
    public function handle(Subscription $subscription, array $standardPlan): void
    {
        $subscription->swapAndInvoice(
            $this->priceIds->forLookupKey((string) $standardPlan['stripe_lookup_key']),
        );
    }
}
