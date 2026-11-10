<?php

namespace App\Services\Subscriptions;

use App\Enums\CancellationOffer;
use App\Enums\CancellationReason;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PriceTiers;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
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
    private const MONTHS_PER_YEAR = 12;

    /** The pause lengths offered, in months; the flow picks the first. */
    public const PAUSE_MONTHS = [1, 2, 3];

    /** How many months one invoice of each billing period covers. */
    private const MONTHS_PER_INVOICE = ['month' => 1, 'year' => self::MONTHS_PER_YEAR];

    /** @var array<string, ?StripeSubscription> */
    private array $stripeSubscriptions = [];

    public function __construct(private SubscribedPlan $subscribedPlan) {}

    /**
     * The subscription the cancel flow works on: the user's own `default` one,
     * running (trial, paid or paused), not already set to cancel, not waiting
     * on a payment, and real — a seeded subscription never reaches Stripe.
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
    public function offersFor(User $user, Subscription $subscription, CancellationReason $reason): array
    {
        // With a pause set, Stripe voids every invoice until it ends: the
        // yearly switch would hand out a free year, and a discount would be
        // spent on voided months. Nothing to offer but the cancellation.
        if ($subscription->hasPause()) {
            return [];
        }

        $offers = match ($reason) {
            CancellationReason::TooExpensive => [
                $this->retentionCoupon($user, $subscription),
                $this->switchToYearly($user, $subscription),
            ],
            CancellationReason::MissingFeature => [['offer' => CancellationOffer::Roadmap]],
            CancellationReason::BankConnectionProblems,
            CancellationReason::TooComplex => [['offer' => CancellationOffer::Support]],
            // No fallback after it: the free plan cannot be offered to someone
            // still paying, and the confirm step already says their data stays.
            CancellationReason::NotUsingEnough => [$this->pause($user, $subscription)],
            // Not something an offer answers: straight on to the confirmation.
            default => [],
        };

        return array_values(array_filter($offers));
    }

    /**
     * The retention discount, with the saving spelled out: the percentage off
     * every invoice its months cover. A repeating coupon counts its months
     * from the day it is applied, so a trial's first charge falls inside them
     * but a paid yearly plan's next renewal usually does not.
     *
     * Null once the user has had it, when the plan is unknown (so the saving
     * cannot be quoted), when no invoice falls inside the discount's months
     * (it would be spent on nothing), and when the subscription already
     * carries a discount the coupon would replace.
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
        $invoices = $this->invoicesWithin($subscription, $plan['billing_period'], now()->addMonths($months));

        if ($invoices === 0) {
            return null;
        }

        return [
            'offer' => CancellationOffer::RetentionCoupon,
            'percentOff' => $percentOff,
            'months' => $months,
            'savedAmount' => round($plan['price'] * $percentOff / 100 * $invoices, 2),
        ];
    }

    /**
     * A pause of a few months, starting when the period already paid for
     * ends, with the day each length would resume on and the price it
     * resumes at. Null when `pauseStartsAt()` says there is none to offer.
     *
     * @return array{offer: CancellationOffer, startsAt: string, price: float, options: list<array{months: int, resumesAt: string}>}|null
     */
    public function pause(User $user, Subscription $subscription): ?array
    {
        $startsAt = $this->pauseStartsAt($user, $subscription);
        $plan = $this->subscribedPlan->for($user, $subscription);

        if ($startsAt === null || $plan === null) {
            return null;
        }

        return [
            'offer' => CancellationOffer::Pause,
            'startsAt' => $startsAt->toIso8601String(),
            'price' => (float) $plan['price'],
            'options' => array_map(fn (int $months): array => [
                'months' => $months,
                'resumesAt' => $this->pauseResumesAt($subscription, $startsAt, $months)->toIso8601String(),
            ], self::PAUSE_MONTHS),
        ];
    }

    /**
     * When a pause would start: the end of the period the user has paid for.
     * Null when there is nothing to pause:
     *
     * - a trial has paid for nothing yet (cancelling it already costs nothing);
     * - a yearly plan would skip a whole year's invoice for a pause of months,
     *   because a voided renewal is not billed again until the next one;
     * - a pause is already set, here or in Stripe;
     * - the subscription carries a discount: its months run on the calendar,
     *   so a pause would spend them on voided invoices, and the price the
     *   pause resumes at is not the one we would quote;
     * - Stripe cannot tell us the period end, or the plan is unknown.
     */
    public function pauseStartsAt(User $user, Subscription $subscription): ?CarbonInterface
    {
        if (
            $subscription->onTrial()
            || $subscription->hasPause()
            || ($this->subscribedPlan->for($user, $subscription)['billing_period'] ?? null) !== 'month'
        ) {
            return null;
        }

        $stripeSubscription = $this->stripeSubscription($subscription);

        if ($stripeSubscription === null || ! empty($stripeSubscription->pause_collection) || $this->hasDiscount($subscription)) {
            return null;
        }

        $startsAt = $this->nextChargeAt($subscription);

        return $startsAt !== null && $startsAt->isFuture() ? $startsAt : null;
    }

    /**
     * The renewal a pause of so many months from `$startsAt` ends on, on
     * Stripe's billing anchor day (see `Subscription::renewalAfter()`).
     */
    public function pauseResumesAt(Subscription $subscription, CarbonInterface $startsAt, int $months): CarbonImmutable
    {
        $anchor = $this->stripeSubscription($subscription)?->billing_cycle_anchor;

        return Subscription::renewalAfter($startsAt, $months, is_int($anchor) ? Carbon::createFromTimestamp($anchor) : null);
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
        $monthly = $this->subscribedPlan->for($user, $subscription);

        if ($yearly === null || $monthly === null) {
            return null;
        }

        $price = (float) $yearly['price'];

        return [
            'offer' => CancellationOffer::SwitchToYearly,
            'price' => $price,
            'savings' => round($monthly['price'] * self::MONTHS_PER_YEAR - $price, 2),
        ];
    }

    /**
     * The yearly plan of the tier a monthly subscriber pays — latam stays
     * latam, a legacy price moves to that tier's yearly — or null when the
     * subscription is not monthly, its tier is unknown, it already carries a
     * discount, or a year there would not cost less than twelve months.
     *
     * @return array<string, mixed>|null
     */
    public function yearlyPlanFor(User $user, Subscription $subscription): ?array
    {
        $plan = $this->subscribedPlan->for($user, $subscription);
        $tier = $this->subscribedPlan->tierFor($user, $subscription);

        // A discounted plan's list-price saving would be a lie, and the swap
        // would charge a full year against what they pay now.
        if ($plan === null || $tier === null || $plan['billing_period'] !== 'month' || $this->hasDiscount($subscription)) {
            return null;
        }

        $yearly = PriceTiers::configuredPlansFor($tier)['yearly'] ?? null;

        return $yearly !== null && (float) $yearly['price'] < $plan['price'] * self::MONTHS_PER_YEAR ? $yearly : null;
    }

    /**
     * The day the plan would stop if cancelled now: the trial end for a trial,
     * today inside a pause, the end of the paid period otherwise. Null when Stripe cannot say, and
     * the flow then talks about "the end of your billing period" instead.
     */
    public function endsAt(Subscription $subscription): ?string
    {
        // Inside a pause the plan is off already, and cancelling ends it today.
        if ($subscription->isPaused()) {
            return now()->toIso8601String();
        }

        return $this->nextChargeAt($subscription)?->toIso8601String();
    }

    /**
     * The next invoice: the trial end for a trial, the end of the paid period
     * otherwise.
     */
    private function nextChargeAt(Subscription $subscription): ?CarbonInterface
    {
        if ($subscription->onTrial()) {
            return $subscription->trial_ends_at;
        }

        $periodEnd = $this->stripeSubscription($subscription)?->items->data[0]->current_period_end ?? null;

        return $periodEnd === null ? null : Carbon::createFromTimestamp($periodEnd);
    }

    /**
     * How many invoices fall between now and the given day, starting with the
     * next charge and repeating every billing period.
     */
    private function invoicesWithin(Subscription $subscription, string $billingPeriod, CarbonInterface $until): int
    {
        $charge = $this->nextChargeAt($subscription);
        $interval = self::MONTHS_PER_INVOICE[$billingPeriod] ?? null;

        if ($charge === null || $interval === null) {
            return 0;
        }

        $invoices = 0;

        for ($charge = $charge->toImmutable(); $charge->lessThanOrEqualTo($until); $charge = $charge->addMonthsNoOverflow($interval)) {
            $invoices++;
        }

        return $invoices;
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
