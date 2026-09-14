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
    /** The plans an offer is described for. */
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
     */
    private function paysUpfront(string $variant): bool
    {
        if ($variant === SubscriptionExperiment::LEGACY) {
            return false;
        }

        foreach (self::PLAN_KEYS as $planKey) {
            if ($this->trialDaysForVariant($variant, $planKey) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * The offer descriptor handed to the frontend so it can render the trial /
     * money-back copy without re-deriving any experiment logic.
     *
     * @return array{variant: string, payNow: bool, refundWindowDays: int, trialDays: array<string, int>}
     */
    public function offerFor(User $user): array
    {
        $variant = $this->variantFor($user);

        $trialDays = [];
        foreach (self::PLAN_KEYS as $planKey) {
            $trialDays[$planKey] = $this->trialDaysForVariant($variant, $planKey);
        }

        return [
            'variant' => $variant,
            'payNow' => $this->paysUpfront($variant),
            'refundWindowDays' => $this->refundWindowDays(),
            'trialDays' => $trialDays,
        ];
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
