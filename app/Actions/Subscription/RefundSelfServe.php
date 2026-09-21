<?php

namespace App\Actions\Subscription;

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Self-service "money-back guarantee" for a subscriber who was charged in full
 * at signup: refund the charge, cancel the subscription immediately, and revoke
 * the user's bank connections (keeping the data they already imported).
 *
 * The page gate (RefundWindow::isOpenFor()) is a cheap predicate over our own
 * columns, so it can let through someone who was never actually charged. Stripe
 * holds the truth about the money, and this is the one moment it matters, so it
 * is asked here: no payment means no refund and nothing else happens either.
 *
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

        // Nothing to give back. Carrying on would stamp the refund, cancel the
        // plan and disconnect the banks for someone who was never charged —
        // taking away what they have and returning nothing, silently. Stop
        // before anything is touched and let the caller report it.
        if ($payment === null) {
            throw new \RuntimeException('No Stripe payment found for this subscription; refusing to refund.');
        }

        $user->refund($payment->asStripePaymentIntent()->id);

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
