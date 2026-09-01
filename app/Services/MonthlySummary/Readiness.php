<?php

namespace App\Services\MonthlySummary;

use App\Enums\BankingConnectionStatus;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides whether a user's closed month can be reported yet.
 *
 * There is no "I have finished importing" signal in the product, and 93% of
 * onboarded users have no bank connected at all, so the proxy is deliberately
 * one condition rather than one per account type: has anything happened in the
 * new month? A successful sync counts, and so does a transaction created by
 * hand or by import. Requiring both would deadlock the mixed user, whose
 * abandoned manual account would block their bank-fed report forever.
 *
 * Measured on July 2026: of the 596 users with transactions that month, 266 saw
 * July change during August — sending on the 1st would have reported a month
 * that was still moving. That is what this guard is for.
 */
class Readiness
{
    /**
     * The month is worth reporting and every source has reported in.
     */
    public function isReady(User $user, Carbon $month): bool
    {
        return $this->hasDataFor($user, $month) && $this->hasActivityAfter($user, $month);
    }

    /**
     * At least one transaction dated inside the closed month. Without this there
     * is no report to write at all.
     */
    public function hasDataFor(User $user, Carbon $month): bool
    {
        return $this->transactionsDatedIn($user, $month)->exists();
    }

    /**
     * Whether the user has a month before the closed one. Half the report is
     * comparisons, so without it they get the simpler first-month email.
     */
    public function hasHistoryBefore(User $user, Carbon $month): bool
    {
        return $this->hasDataFor($user, $month->copy()->subMonth());
    }

    /**
     * Signs of life in the month after the closed one: a bank that synced, or a
     * transaction the user created. `last_synced_at` is only written on the
     * success path of the sync job, so its presence already means "satisfactory".
     */
    public function hasActivityAfter(User $user, Carbon $month): bool
    {
        $from = $month->copy()->addMonth()->startOfMonth();

        $synced = BankingConnection::query()
            ->where('user_id', $user->id)
            ->where('status', BankingConnectionStatus::Active)
            ->where('last_synced_at', '>=', $from)
            ->exists();

        if ($synced) {
            return true;
        }

        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $from)
            ->exists();
    }

    /**
     * Who is worth nudging: someone who was already using the app and has been
     * caught mid-month, not a dormant account. Sending "update your data" to
     * every onboarded user without a closed month would be a reactivation blast
     * dressed up as an operational notice.
     */
    public function deservesReminder(User $user, Carbon $month): bool
    {
        if ($user->last_active_at === null || $user->last_active_at->lt(now()->subDays(30))) {
            return false;
        }

        return $this->hasDataFor($user, $month) || $this->hasHistoryBefore($user, $month);
    }

    /**
     * @return Builder<Transaction>
     */
    private function transactionsDatedIn(User $user, Carbon $month)
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('transaction_date', [
                $month->copy()->startOfMonth(),
                $month->copy()->endOfMonth(),
            ]);
    }
}
