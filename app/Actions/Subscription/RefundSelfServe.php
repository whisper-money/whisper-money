<?php

namespace App\Actions\Subscription;

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Self-service "money-back guarantee" for the pay_now experiment variant:
 * refund the upfront charge, cancel the subscription immediately, and revoke
 * the user's bank connections (keeping the data they already imported).
 *
 * Eligibility is enforced by the caller via ExperimentOffer::canSelfRefund().
 */
class RefundSelfServe
{
    public function __construct(private DisconnectBankingConnection $disconnect) {}

    public function handle(User $user): void
    {
        $subscription = $user->subscription('default');

        if ($subscription === null) {
            return;
        }

        $payment = $subscription->latestPayment();

        if ($payment !== null) {
            $user->refund($payment->id);
        }

        $subscription->cancelNow();

        DB::transaction(function () use ($subscription): void {
            $subscription->forceFill(['refunded_at' => now()])->save();
        });

        $user->bankingConnections()->get()->each(function ($connection): void {
            $this->disconnect->handle($connection, deleteAccounts: false);
        });
    }
}
