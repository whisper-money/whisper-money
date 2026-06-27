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
    public function variantFor(User $user): string
    {
        return Feature::for($user)->value(SubscriptionExperiment::class);
    }

    /**
     * Trial days to apply at checkout for the given plan and the user's variant.
     */
    public function trialDaysFor(User $user, string $planKey): int
    {
        return $this->trialDaysForVariant($this->variantFor($user), $planKey);
    }

    private function trialDaysForVariant(string $variant, string $planKey): int
    {
        return match ($variant) {
            SubscriptionExperiment::PAY_NOW => 0,
            SubscriptionExperiment::REDUCED_TRIAL => (int) config(
                "subscriptions.experiment.reduced_trial.{$planKey}",
                config("subscriptions.plans.{$planKey}.trial_days", 0),
            ),
            default => (int) config("subscriptions.plans.{$planKey}.trial_days", 0),
        };
    }

    public function refundWindowDays(): int
    {
        return (int) config('subscriptions.experiment.pay_now_refund_window_days', 3);
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

        return [
            'variant' => $variant,
            'payNow' => $variant === SubscriptionExperiment::PAY_NOW,
            'refundWindowDays' => $this->refundWindowDays(),
            'trialDays' => [
                'monthly' => $this->trialDaysForVariant($variant, 'monthly'),
                'yearly' => $this->trialDaysForVariant($variant, 'yearly'),
            ],
        ];
    }

    /**
     * Whether the user can still self-refund: pay_now variant, an active
     * subscription, inside the refund window, and not already refunded.
     */
    public function canSelfRefund(User $user): bool
    {
        if ($this->variantFor($user) !== SubscriptionExperiment::PAY_NOW) {
            return false;
        }

        $subscription = $user->subscription('default');

        if ($subscription === null || $subscription->refunded_at !== null || ! $subscription->active()) {
            return false;
        }

        return $this->refundDeadlineFor($subscription)->isFuture();
    }

    public function refundDeadlineFor(Subscription $subscription): CarbonImmutable
    {
        return CarbonImmutable::parse($subscription->created_at)->addDays($this->refundWindowDays());
    }
}
