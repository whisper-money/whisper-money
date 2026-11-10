<?php

namespace App\Services\Subscriptions;

use App\Enums\CancellationOffer;
use App\Enums\CancellationReason;
use App\Models\User;
use App\Support\PriceTiers;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;
use Stripe\Subscription as StripeSubscription;

/**
 * What the cancel flow can offer a subscriber, and for which reason.
 *
 * The server decides, not the page: whether the retention discount is still
 * available is a fact about this user (it is offered once, ever) and about
 * their subscription in Stripe (it must not replace a discount they already
 * have), and the yearly price depends on the tier they pay.
 *
 * One reason-matched offer, and at most one fallback after it. The flow always
 * lets the user carry on cancelling past both.
 */
class CancellationOffers
{
    /** How many months one invoice of each billing period covers. */
    private const MONTHS_PER_INVOICE = ['month' => 1, 'year' => 12];

    /** @var array<string, ?StripeSubscription> */
    private array $stripeSubscriptions = [];

    public function __construct(private SubscribedPlan $subscribedPlan) {}

    /**
     * The subscription the cancel flow works on: the user's own `default` one,
     * running (trial or paid), not already set to cancel, not waiting on a
     * payment, and real — a seeded subscription never reaches Stripe.
     */
    public function cancellableSubscription(User $user): ?Subscription
    {
        $subscription = $user->subscription('default');

        if (
            $subscription === null
            || $user->cannotUseStripe()
            || ! $subscription->valid()
            || $subscription->onGracePeriod()
            || $subscription->hasIncompletePayment()
        ) {
            return null;
        }

        return $subscription;
    }

    /**
     * The offers for a reason, in the order the flow shows them.
     *
     * @return list<array<string, mixed>>
     */
    public function for(User $user, Subscription $subscription, CancellationReason $reason): array
    {
        $offers = match ($reason) {
            CancellationReason::TooExpensive => [
                $this->retentionCoupon($user, $subscription),
                $this->switchToYearly($user, $subscription),
            ],
            CancellationReason::MissingFeature => [['offer' => CancellationOffer::Roadmap]],
            CancellationReason::BankConnectionProblems,
            CancellationReason::TooComplex => [['offer' => CancellationOffer::Support]],
            // The pause offer (T6) goes here. Until then there is none: the
            // free plan cannot be offered to someone still paying, and the
            // confirm step already tells them their data stays.
            CancellationReason::NotUsingEnough => [],
            // Not something an offer answers: straight on to the confirmation.
            default => [],
        };

        return array_values(array_filter($offers));
    }

    /**
     * The retention discount, with the saving spelled out: the percentage off
     * every invoice its months cover. Null once the user has had it, when the
     * plan is unknown (so the saving cannot be quoted) and when the
     * subscription already carries a discount the coupon would replace.
     *
     * @return array{offer: CancellationOffer, percentOff: int, months: int, savedAmount: float}|null
     */
    public function retentionCoupon(User $user, Subscription $subscription): ?array
    {
        $plan = $this->subscribedPlan->for($user, $subscription);

        if (
            $user->retention_coupon_redeemed_at !== null
            || blank(config('subscriptions.retention_coupon.id'))
            || $plan === null
            || $this->hasDiscount($subscription)
        ) {
            return null;
        }

        $percentOff = (int) config('subscriptions.retention_coupon.percent_off');
        $months = (int) config('subscriptions.retention_coupon.duration_in_months');
        $invoices = max(1, intdiv($months, self::MONTHS_PER_INVOICE[$plan['billing_period']] ?? $months));

        return [
            'offer' => CancellationOffer::RetentionCoupon,
            'percentOff' => $percentOff,
            'months' => $months,
            'savedAmount' => round($plan['price'] * $percentOff / 100 * $invoices, 2),
        ];
    }

    /**
     * The move to the yearly plan, for a monthly subscriber, with what a year
     * of it saves over twelve months of the monthly price.
     *
     * @return array{offer: CancellationOffer, price: float, savings: float}|null
     */
    public function switchToYearly(User $user, Subscription $subscription): ?array
    {
        $yearly = $this->yearlyPlanFor($user, $subscription);

        if ($yearly === null) {
            return null;
        }

        $monthlyPrice = (float) $this->subscribedPlan->for($user, $subscription)['price'];
        $price = (float) $yearly['price'];

        return [
            'offer' => CancellationOffer::SwitchToYearly,
            'price' => $price,
            'savings' => round($monthlyPrice * 12 - $price, 2),
        ];
    }

    /**
     * The yearly plan of the tier a monthly subscriber pays — latam stays
     * latam, a legacy price moves to that tier's yearly — or null when the
     * subscription is not monthly, its tier is unknown, or a year there would
     * not cost less than twelve months.
     *
     * @return array<string, mixed>|null
     */
    public function yearlyPlanFor(User $user, Subscription $subscription): ?array
    {
        $plan = $this->subscribedPlan->for($user, $subscription);
        $tier = $this->subscribedPlan->tierFor($user, $subscription);

        if ($plan === null || $tier === null || $plan['billing_period'] !== 'month') {
            return null;
        }

        $yearly = PriceTiers::configuredPlansFor($tier)['yearly'] ?? null;

        return $yearly !== null && (float) $yearly['price'] < $plan['price'] * 12 ? $yearly : null;
    }

    /**
     * The day the plan would stop if cancelled now: the trial end for a trial,
     * the end of the paid period otherwise. Null when Stripe cannot say, and
     * the flow then talks about "the end of your billing period" instead.
     */
    public function endsAt(Subscription $subscription): ?string
    {
        if ($subscription->onTrial()) {
            return $subscription->trial_ends_at?->toIso8601String();
        }

        $periodEnd = $this->stripeSubscription($subscription)?->items?->data[0]?->current_period_end ?? null;

        return $periodEnd === null ? null : now()->setTimestamp($periodEnd)->toIso8601String();
    }

    /**
     * Applying the coupon replaces every discount on the subscription, and a
     * founder's 100% off forever must never be traded for 30% off. Unknown
     * counts as having one: an offer we cannot check is not made.
     */
    private function hasDiscount(Subscription $subscription): bool
    {
        $stripeSubscription = $this->stripeSubscription($subscription);

        return $stripeSubscription === null || ! empty($stripeSubscription->discounts);
    }

    /**
     * One Stripe read per request, shared by the discount check and the period end.
     */
    private function stripeSubscription(Subscription $subscription): ?StripeSubscription
    {
        if (array_key_exists($subscription->stripe_id, $this->stripeSubscriptions)) {
            return $this->stripeSubscriptions[$subscription->stripe_id];
        }

        try {
            $stripeSubscription = $subscription->asStripeSubscription();
        } catch (ApiErrorException $exception) {
            report($exception);
            $stripeSubscription = null;
        }

        return $this->stripeSubscriptions[$subscription->stripe_id] = $stripeSubscription;
    }
}
