<?php

namespace App\Actions\Subscription;

use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * Gives a trial two more weeks, once per user, ever: the cancel flow's offer
 * for "I'm not using it enough" during a trial.
 *
 * The new end is the end of the day, `DAYS` after the current trial end, the
 * same rounding the checkout uses (`SubscriptionController::subscribe()`), so
 * Stripe's "days left" never reads one short. Nothing is prorated: there is
 * nothing to bill until the trial ends. Cashier's webhook syncs `trial_ends_at`
 * from Stripe too; it is mirrored here so the billing page shows the new end
 * on the redirect. `users.trial_ends_at` (Cashier's generic trial) is not used
 * by this app, so it is left alone.
 *
 * Like `ApplyRetentionCoupon`, the claim is stamped before Stripe is asked,
 * with a conditional update only one request can win, and given back if
 * Stripe refuses.
 *
 * `$reactivate` also takes back a cancellation scheduled for the trial end,
 * in the same Stripe update, for a link that extends and keeps the plan.
 */
class ExtendTrial
{
    public const DAYS = 14;

    /**
     * Whether the user may still have their trial extended on this
     * subscription: it is on a trial, has no pause, is a real Stripe one, and
     * the user never had an extension. Checks only what is stored here; the
     * whole offer, with what Stripe says (a discount, a pause set there), is
     * `CancellationOffers::extendTrial()`, which callers should check first.
     */
    public function isAvailable(User $user, Subscription $subscription): bool
    {
        return $user->trial_extended_at === null
            && ! $user->cannotUseStripe()
            && $subscription->onTrial()
            && ! $subscription->hasPause();
    }

    /**
     * When the extended trial would end, to the second as Stripe and the
     * database keep it.
     */
    public function extendedTrialEndsAt(Subscription $subscription): CarbonImmutable
    {
        return CarbonImmutable::instance($subscription->trial_ends_at)->addDays(self::DAYS)->endOfDay()->startOfSecond();
    }

    /**
     * @return CarbonImmutable|null The new trial end; null when the trial cannot be extended (no
     *                              longer on it, or the user had their extension already).
     *
     * @throws ApiErrorException
     */
    public function handle(User $user, Subscription $subscription, bool $reactivate = false): ?CarbonImmutable
    {
        if (! $this->isAvailable($user, $subscription)) {
            return null;
        }

        $trialEndsAt = $this->extendedTrialEndsAt($subscription);

        $claimed = User::query()
            ->whereKey($user->getKey())
            ->whereNull('trial_extended_at')
            ->update(['trial_extended_at' => now()]);

        if ($claimed === 0) {
            return null;
        }

        try {
            $stripeSubscription = $subscription->updateStripeSubscription([
                'trial_end' => $trialEndsAt->getTimestamp(),
                'proration_behavior' => 'none',
                ...($reactivate ? ['cancel_at_period_end' => false] : []),
            ]);
        } catch (Throwable $exception) {
            User::query()->whereKey($user->getKey())->update(['trial_extended_at' => null]);

            throw $exception;
        }

        $subscription->forceFill([
            'trial_ends_at' => $trialEndsAt,
            ...($reactivate ? ['ends_at' => null, 'stripe_status' => $stripeSubscription->status] : []),
        ])->save();

        $user->refresh();

        return $trialEndsAt;
    }
}
