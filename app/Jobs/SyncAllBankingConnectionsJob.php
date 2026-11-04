<?php

namespace App\Jobs;

use App\Enums\BankingSyncTrigger;
use App\Models\BankingConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAllBankingConnectionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A connection that synced successfully this recently is skipped by the
     * sweep. Banks cap PSD2 consents at four unattended accesses a day, exactly
     * the six-hour cadence, so a second sweep inside one window costs the
     * evening sync a 429. Well under six hours, so a connection that synced on
     * the previous sweep is always due on this one.
     */
    public const int RECENTLY_SYNCED_HOURS = 3;

    /**
     * One attempt only. If a run outlives the database queue's retry_after (or is
     * killed by the worker timeout without being released), the queue hands the
     * job to another worker; with a single try that copy fails with
     * MaxAttemptsExceededException before handle() runs, instead of sweeping and
     * dispatching every connection again. The next scheduled run is six hours away.
     */
    public int $tries = 1;

    /**
     * Below the database queue retry_after (900s), so the worker, not the queue,
     * decides when a stuck run ends.
     */
    public int $timeout = 600;

    public function __construct(
        public bool $fullSync = false,
    ) {}

    public function handle(): void
    {
        $recentlySyncedCutoff = now()->subHours(self::RECENTLY_SYNCED_HOURS);

        BankingConnection::query()
            ->eligibleForScheduledSync()
            ->where(function (Builder $query) use ($recentlySyncedCutoff) {
                $query->notSyncedSince($recentlySyncedCutoff)
                    ->orWhere(fn (Builder $query) => $query->consentExpiredWhileActive());
            })
            ->each(function (BankingConnection $connection) {
                SyncBankingConnectionJob::dispatch($connection, $this->fullSync, BankingSyncTrigger::Scheduled);
            });
    }
}
