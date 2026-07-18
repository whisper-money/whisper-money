<?php

namespace App\Services\Subscriptions;

use App\Features\PriceExperiment;
use App\Features\SubscriptionExperiment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Subscription;
use Laravel\Pennant\Feature;

/**
 * Translates a user's experiment variants into the concrete offer they get: the
 * plan prices (price experiment), how many trial days per plan, whether they pay
 * immediately, and whether they can still trigger the self-service refund. Single
 * source of truth shared by the checkout, the paywall and the billing settings
 * screen, so a user is always shown exactly the price and terms they are charged.
 */
class ExperimentOffer
{
    public function variantFor(User $user): string
    {
        return Feature::for($user)->value(SubscriptionExperiment::class);
    }

    public function priceVariantFor(User $user): string
    {
        return Feature::for($user)->value(PriceExperiment::class);
    }

    /**
     * The plans config with the user's price-experiment variant applied: price,
     * original_price and Stripe lookup key swapped for the assigned tier. Control
     * and legacy users get the unchanged config. Drives both the paywall display
     * and the checkout charge, so the shown price always equals the charged one.
     *
     * @return array<string, array<string, mixed>>
     */
    public function pricingPlansFor(User $user): array
    {
        $plans = (array) config('subscriptions.plans', []);
        $overrides = (array) config('subscriptions.price_experiment.variants.'.$this->priceVariantFor($user), []);

        foreach ($overrides as $planKey => $override) {
            if (! isset($plans[$planKey])) {
                continue;
            }

            $plans[$planKey]['price'] = $override['price'];
            $plans[$planKey]['original_price'] = $override['original_price'] ?? null;
            $plans[$planKey]['stripe_lookup_key'] = $override['lookup'];
        }

        return $plans;
    }

    /**
     * Stripe lookup key to charge for the given plan and the user's price variant.
     * Falls back to the control plan's key for control/legacy users.
     */
    public function lookupKeyFor(User $user, string $planKey): string
    {
        return (string) config(
            "subscriptions.price_experiment.variants.{$this->priceVariantFor($user)}.{$planKey}.lookup",
            config("subscriptions.plans.{$planKey}.stripe_lookup_key"),
        );
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
