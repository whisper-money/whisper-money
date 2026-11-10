<?php

namespace App\Services\Subscriptions;

use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Subscription;

/**
 * The in-app reminder that a free trial is about to end, rationed on purpose:
 * it only exists 7, 3 and 1 days before the trial end (in the user's own
 * calendar), and closing it hides it until the next of those days. The
 * dismissal is kept on the user row, so closing it on the laptop also hides it
 * on the phone.
 *
 * A trial set to cancel (Cashier's grace period) gets the same reminder
 * without a charge to quote: what stops, and the way to keep the plan.
 */
class TrialEndingBanner
{
    /** The days left at which the banner comes back, smallest first. */
    public const THRESHOLDS = [1, 3, 7];

    public function __construct(
        private SubscribedPlan $subscribedPlan,
        private TrialProgress $progress,
    ) {}

    /**
     * What the banner says, or null when it does not show. Everything before
     * the plan and stats lookups reads the user row and the subscriptions the
     * request already loaded, so a user it does not apply to costs no query.
     *
     * @return array{daysLeft: int, threshold: int, trialEndsOn: string, canceled: bool, charge: array{amount: int, currency: string, period: string}|null, stats: array{synced: int, categorizedPercent: int}}|null
     */
    public function for(User $user): ?array
    {
        $subscription = $this->trialOf($user);

        if ($subscription === null) {
            return null;
        }

        $trialEnd = $this->localTrialEnd($user, $subscription);
        $daysLeft = $this->daysLeft($user, $trialEnd);
        $threshold = $this->thresholdFor($daysLeft);

        if ($threshold === null || $user->trial_banner_dismissed_for === $this->dismissalKey($trialEnd, $threshold)) {
            return null;
        }

        $canceled = $subscription->onGracePeriod();

        return [
            'daysLeft' => $daysLeft,
            'threshold' => $threshold,
            'trialEndsOn' => $trialEnd->toDateString(),
            'canceled' => $canceled,
            'charge' => $canceled ? null : $this->chargeFor($user, $subscription),
            'stats' => $this->progress->for($user, $subscription),
        ];
    }

    /**
     * Hide the banner until the next threshold. A no-op when it is not
     * showing, so a stale tab cannot dismiss a threshold it never displayed.
     */
    public function dismiss(User $user): void
    {
        $subscription = $this->trialOf($user);

        if ($subscription === null) {
            return;
        }

        $trialEnd = $this->localTrialEnd($user, $subscription);
        $threshold = $this->thresholdFor($this->daysLeft($user, $trialEnd));

        if ($threshold !== null) {
            $user->forceFill(['trial_banner_dismissed_for' => $this->dismissalKey($trialEnd, $threshold)])->save();
        }
    }

    /**
     * The user's default subscription while it is on its trial, for an account
     * that can reach billing at all.
     */
    private function trialOf(User $user): ?Subscription
    {
        if (! config('subscriptions.enabled') || $user->cannotUseStripe()) {
            return null;
        }

        $subscription = $user->subscription('default');

        return $subscription !== null && $subscription->onTrial() ? $subscription : null;
    }

    private function localTrialEnd(User $user, Subscription $subscription): CarbonImmutable
    {
        return CarbonImmutable::instance($subscription->trial_ends_at)->setTimezone($user->localTimezone());
    }

    /** Whole calendar days from today to the trial end, both on the user's clock. */
    private function daysLeft(User $user, CarbonImmutable $trialEnd): int
    {
        $today = CarbonImmutable::instance($user->localNow())->startOfDay();

        return (int) round($today->diffInDays($trialEnd->startOfDay(), false));
    }

    /**
     * The threshold the remaining days fall under: 7 from seven days out, 3
     * from three, 1 from the day before until the trial ends.
     */
    private function thresholdFor(int $daysLeft): ?int
    {
        if ($daysLeft < 0) {
            return null;
        }

        foreach (self::THRESHOLDS as $threshold) {
            if ($daysLeft <= $threshold) {
                return $threshold;
            }
        }

        return null;
    }

    /** Keyed on the trial too, so a second trial later starts with nothing dismissed. */
    private function dismissalKey(CarbonImmutable $trialEnd, int $threshold): string
    {
        return $trialEnd->toDateString().':'.$threshold;
    }

    /**
     * What the first charge will be, read off the price the subscription pays
     * ({@see SubscribedPlan}); null when that price is not one we know, and the
     * banner then names the date alone rather than guess an amount.
     *
     * @return array{amount: int, currency: string, period: string}|null
     */
    private function chargeFor(User $user, Subscription $subscription): ?array
    {
        $plan = $this->subscribedPlan->for($user, $subscription);

        if ($plan === null || ! in_array($plan['billing_period'], ['month', 'year'], true)) {
            return null;
        }

        $currency = strtoupper((string) config('cashier.currency', 'eur'));

        return [
            'amount' => Money::toMinor($plan['price'], $currency),
            'currency' => $currency,
            'period' => $plan['billing_period'],
        ];
    }
}
