<?php

namespace App\Services\Subscriptions;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
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

    /** Followed by the subscription id; see {@see upcomingInvoice()}. */
    public const FIRST_CHARGE_CACHE_PREFIX = 'trial-first-charge:';

    public function __construct(
        private SubscribedPlan $subscribedPlan,
        private TrialProgress $progress,
    ) {}

    /**
     * What the banner says, or null when it does not show. Everything before
     * the plan and stats lookups reads the user row and the subscriptions the
     * request already loaded, so a user it does not apply to costs no query.
     *
     * @return array{daysLeft: int, threshold: int, trialEndsOn: string, dismissalKey: string, canceled: bool, charge: array{amount: int, currency: string, period: string}|null, stats: array{synced: int, categorizedPercent: int}|null}|null
     */
    public function for(User $user): ?array
    {
        $subscription = $this->trialOf($user);

        if ($subscription === null) {
            return null;
        }

        $trialEnd = $this->localTrialEnd($user, $subscription);
        $daysLeft = $this->daysBetween($user->localNow(), $trialEnd);
        $threshold = $this->thresholdFor($daysLeft, $this->daysBetween($subscription->created_at, $trialEnd));

        if ($threshold === null) {
            return null;
        }

        $dismissalKey = $this->dismissalKey($trialEnd, $threshold);

        if ($user->trial_banner_dismissed_for === $dismissalKey) {
            return null;
        }

        $canceled = $subscription->onGracePeriod();

        // A trial set to cancel makes no case for the plan, so it is not counted.
        return [
            'daysLeft' => $daysLeft,
            'threshold' => $threshold,
            'trialEndsOn' => $trialEnd->toDateString(),
            'dismissalKey' => $dismissalKey,
            'canceled' => $canceled,
            'charge' => $canceled ? null : $this->chargeFor($user, $subscription),
            'stats' => $canceled ? null : $this->progress->cachedFor($user, $subscription),
        ];
    }

    /**
     * Hide the banner until the next threshold. Only the key of the banner
     * showing now is taken, so a tab left open across a threshold cannot
     * close the next banner before the user has seen it.
     */
    public function dismiss(User $user, string $dismissalKey): void
    {
        if (($this->for($user)['dismissalKey'] ?? null) === $dismissalKey) {
            $user->forceFill(['trial_banner_dismissed_for' => $dismissalKey])->save();
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

    /**
     * Whole calendar days from `$from` to the trial end, both on the trial
     * end's clock (the user's). Rounded, because a day across a DST change
     * lasts 23 or 25 hours.
     */
    private function daysBetween(DateTimeInterface $from, CarbonImmutable $trialEnd): int
    {
        $fromDay = CarbonImmutable::instance($from)->setTimezone($trialEnd->getTimezone())->startOfDay();

        return (int) round($fromDay->diffInDays($trialEnd->startOfDay(), false));
    }

    /**
     * The threshold the remaining days fall under: 7 from seven days out, 3
     * from three, 1 from the day before until the trial ends. A threshold the
     * trial started inside is never reached, so a 7-day trial is first
     * reminded at 3 days rather than the moment it starts.
     */
    private function thresholdFor(int $daysLeft, int $daysAtStart): ?int
    {
        if ($daysLeft < 0) {
            return null;
        }

        foreach (self::THRESHOLDS as $threshold) {
            if ($daysLeft <= $threshold) {
                return $daysAtStart > $threshold ? $threshold : null;
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
     * What the first charge will be: the amount Stripe's upcoming invoice is
     * due for, so a promotion code or tax is quoted as charged, and the period
     * of the price the subscription pays ({@see SubscribedPlan}). Null when
     * either is unknown, and the banner then names the date alone rather than
     * guess an amount.
     *
     * @return array{amount: int, currency: string, period: string}|null
     */
    private function chargeFor(User $user, Subscription $subscription): ?array
    {
        $plan = $this->subscribedPlan->for($user, $subscription);

        if ($plan === null || ! in_array($plan['billing_period'], ['month', 'year'], true)) {
            return null;
        }

        $invoice = $this->upcomingInvoice($subscription);

        // Nothing due (a 100% code) is no charge to warn about.
        return $invoice === null || $invoice['amount'] <= 0 ? null : [
            'amount' => $invoice['amount'],
            'currency' => strtoupper($invoice['currency']),
            'period' => $plan['billing_period'],
        ];
    }

    /**
     * Stripe's preview of the invoice the trial ends with, cached per
     * subscription: the banner asks on every page of the trial's last week.
     * A failed preview is kept briefly so an outage does not slow every page.
     *
     * @return array{amount: int, currency: string}|null
     */
    private function upcomingInvoice(Subscription $subscription): ?array
    {
        $key = self::FIRST_CHARGE_CACHE_PREFIX.$subscription->id;

        if (Cache::has($key)) {
            return Cache::get($key)['invoice'];
        }

        $invoice = rescue(function () use ($subscription): ?array {
            $preview = $subscription->upcomingInvoice();

            return $preview === null ? null : [
                'amount' => $preview->rawAmountDue(),
                'currency' => (string) $preview->asStripeInvoice()->currency,
            ];
        });

        Cache::put($key, ['invoice' => $invoice], $invoice === null ? now()->addMinutes(10) : now()->addHours(6));

        return $invoice;
    }
}
