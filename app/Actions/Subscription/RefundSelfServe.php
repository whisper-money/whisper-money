<?php

namespace App\Actions\Subscription;

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Self-service "money-back guarantee" for the pay_now experiment variant:
 * refund the upfront charge, cancel the subscription immediately, and revoke
 * the user's bank connections (keeping the data they already imported).
 *
 * Eligibility is enforced by the caller via ExperimentOffer::canSelfRefund().
 * The refund is stamped before the cancel/disconnect steps run so that a
 * failure in those steps can never leave a refunded-but-active subscription
 * that could be refunded a second time; the cleanup is best-effort and logged.
 */
class RefundSelfServe
{
    public function __construct(private DisconnectBankingConnection $disconnect) {}

    public function handle(User $user): void
    {
        $subscription = $user->subscription('default');

        if ($subscription === null || $subscription->refunded_at !== null) {
            return;
        }

        $payment = $subscription->latestPayment();

        if ($payment !== null) {
            $user->refund($payment->asStripePaymentIntent()->id);
        }

        $subscription->forceFill(['refunded_at' => now()])->save();

        try {
            $subscription->cancelNow();

            $user->bankingConnections()->get()->each(function (BankingConnection $connection): void {
                $this->disconnect->handle($connection, deleteAccounts: false);
            });
        } catch (\Throwable $exception) {
            Log::error('Self-serve refund issued but post-refund cleanup failed', [
                'user_id' => $user->getKey(),
                'subscription_id' => $subscription->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
