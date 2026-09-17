<?php

namespace App\Services\Subscriptions;

use App\Features\SubscriptionExperiment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Subscription;
use Laravel\Pennant\Feature;

/**
 * Translates a user's experiment variant into the concrete offer they get:
 * how many trial days per plan, whether they pay immediately, and whether they
 * can still trigger the self-service refund. Single source of truth shared by
 * the checkout, the paywall and the billing settings screen.
 */
class ExperimentOffer
{
    /**
     * The recurring plans an offer is described for, listed rather than read
     * from config: a variant charges upfront when it zeroes the trial on *these*
     * two, so a plan added later (a one-off lifetime licence, say) must not be
     * able to make every variant look upfront by having no trial of its own.
     */
    private const PLAN_KEYS = ['monthly', 'yearly'];

    public function variantFor(User $user): string
    {
        return Feature::for($user)->value(SubscriptionExperiment::class);
    }

    /**
     * Trial days to apply at checkout for the given plan and the user's variant:
     * the variant's own override, or the plan default when it declares none.
     */
    public function trialDaysFor(User $user, string $planKey): int
    {
        return $this->trialDaysForVariant($this->variantFor($user), $planKey);
    }

    private function trialDaysForVariant(string $variant, string $planKey): int
    {
        return (int) config(
            "subscriptions.experiment.variants.{$variant}.trial_days.{$planKey}",
            config("subscriptions.plans.{$planKey}.trial_days", 0),
        );
    }

    public function refundWindowDays(): int
    {
        return (int) config('subscriptions.experiment.refund_window_days', 3);
    }

    /**
     * Whether the variant charges upfront: no trial on any plan. That is what
     * earns the money-back window — the user has already been charged, so the
     * only way back out is a refund.
     *
     * Legacy is answered the same way as any variant rather than refused
     * outright: the plans themselves now charge in full at signup, so a user
     * with no variant is an upfront payer and has the same refund to claim. A
     * `trial` variant, or a trial put back on the plans, takes it away again
     * for whoever it applies to.
     */
    private function paysUpfront(string $variant): bool
    {
        foreach (self::PLAN_KEYS as $planKey) {
            if ($this->trialDaysForVariant($variant, $planKey) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the user can still self-refund: an upfront-paying variant, an
     * active subscription, inside the refund window, and not already refunded.
     */
    public function canSelfRefund(User $user): bool
    {
        if (! $this->paysUpfront($this->variantFor($user))) {
            return false;
        }

        $subscription = $user->subscription('default');

        if ($subscription === null || $subscription->refunded_at !== null || ! $subscription->active()) {
            return false;
        }

        if ($user->hasSeededSubscription()) {
            return false;
        }

        return $this->refundDeadlineFor($subscription)->isFuture();
    }

    public function refundDeadlineFor(Subscription $subscription): CarbonImmutable
    {
        return CarbonImmutable::parse($subscription->created_at)->addDays($this->refundWindowDays());
    }
}
