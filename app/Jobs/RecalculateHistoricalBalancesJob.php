<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\Banking\BalanceSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rebuild a connected account's derived balance history after transactions land
 * on days that history already covers.
 *
 * The bank's own sync recalculates as it goes, but an import does not: it is
 * client-side and POSTs one transaction at a time, so filling in months the bank
 * never reported would otherwise leave the history untouched. Dispatching this
 * per row is fine because it is {@see ShouldBeUnique} keyed by account, which
 * collapses a whole import's worth of dispatches into a single queued run.
 *
 * The condition is re-checked on execution and the walk itself is idempotent, so
 * a dispatch that no longer applies - the account gone, or disconnected since -
 * is a no-op rather than a failure.
 */
class RecalculateHistoricalBalancesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public Account $account) {}

    public function uniqueId(): string
    {
        return $this->account->id;
    }

    public function handle(BalanceSyncService $balanceSync): void
    {
        $account = $this->account->fresh();

        if ($account === null || ! $account->isConnected()) {
            return;
        }

        $balanceSync->calculateHistoricalBalances($account);
    }
}
