<?php

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Give the connections this bug stranded a way back.
     *
     * An unclassified 401/403 from Enable Banking was read as an auth failure,
     * which parks the connection in Error with consecutive_sync_failures above
     * the scheduled-retry ceiling. Both the scheduler and `banking:sync` filter
     * those out, so nothing ever dispatched them again — and since their consent
     * had not actually lapsed they never showed up in the "reconnect your bank"
     * notice either. They simply stopped syncing, silently, some of them for
     * months.
     *
     * Clearing the counter puts them back in the scheduled rotation, where the
     * fixed classification decides their real fate: they resume, or the bank
     * says the consent is gone and they expire properly — with the email and the
     * notice the user should have had all along.
     */
    public function up(): void
    {
        BankingConnection::query()
            ->where('provider', BankingProvider::EnableBanking)
            ->where('status', BankingConnectionStatus::Error)
            ->where('consecutive_sync_failures', '>=', SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES)
            ->update(['consecutive_sync_failures' => 0]);
    }

    public function down(): void
    {
        // One-off repair: the counters it cleared carried no information worth
        // restoring, and re-parking these connections would only re-strand them.
    }
};
