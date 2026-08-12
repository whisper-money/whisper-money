<?php

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Give the connections stranded just before #757 a way back.
     *
     * Until #757 (2026-08-10) a classified-transient failure on a final attempt
     * still incremented consecutive_sync_failures, so three cycles of a bank
     * being unreachable dropped the connection out of every scheduled sync. Two
     * connections crossed that line in the hours before it deployed - one of them
     * having never completed a single sync since 2026-06-07. The fix cannot reach
     * them: the schedulers filter them out before any job runs.
     *
     * Nothing proactively tells these users. Their consent is still valid, so the
     * reconnect notice never mentions them; the connections page does offer a Sync
     * button that resets the counter, but only if they think to look.
     *
     * Matched exactly, not with >=: handlePermanentError parks auth failures at
     * MAX_SCHEDULED_RETRIES + 1 on purpose, and un-parking one would 401 on the
     * next cycle and send the user a second "authentication failed" email. The
     * counting path can only ever produce exactly MAX_SCHEDULED_RETRIES, because
     * the scheduler gate stops dispatching at that point.
     */
    public function up(): void
    {
        BankingConnection::query()
            ->where('status', BankingConnectionStatus::Error)
            ->where('consecutive_sync_failures', SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES)
            ->update(['consecutive_sync_failures' => 0]);
    }

    public function down(): void
    {
        // One-off repair: the counters it cleared carried no information worth
        // restoring, and re-parking these connections would only re-strand them.
    }
};
