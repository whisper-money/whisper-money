<?php

namespace App\Actions\Subscription;

use App\Models\User;
use Laravel\Cashier\Exceptions\InvalidCoupon;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * Puts the cancel flow's retention discount on a subscription, once per user.
 *
 * The user's claim is stamped before Stripe is asked, with a conditional update
 * that only one request can win, so two tabs (or a script) cannot both get the
 * discount. If Stripe then refuses, the claim is given back: the user never got
 * the discount, so it is still theirs to take.
 */
class ApplyRetentionCoupon
{
    /**
     * @return bool Whether the discount was applied; false when the user had it already.
     *
     * @throws ApiErrorException
     * @throws InvalidCoupon
     */
    public function handle(User $user, Subscription $subscription): bool
    {
        $claimed = User::query()
            ->whereKey($user->getKey())
            ->whereNull('retention_coupon_redeemed_at')
            ->update(['retention_coupon_redeemed_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        try {
            $subscription->applyCoupon((string) config('subscriptions.retention_coupon.id'));
        } catch (Throwable $exception) {
            User::query()->whereKey($user->getKey())->update(['retention_coupon_redeemed_at' => null]);

            throw $exception;
        }

        $user->refresh();

        return true;
    }
}
