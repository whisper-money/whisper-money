<?php

use App\Enums\TransactionSource;
use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Soft-delete the pending copies the sync used to import before it started
     * skipping un-settled Enable Banking statuses.
     *
     * A purchase delivered as PDNG arrived again as BOOK with different content,
     * so both rows sat in the ledger and every balance counted the money twice.
     * The sync now imports only settled transactions; this clears what the old
     * behaviour already wrote. Production: 7916 N26 rows carry raw_data, of
     * which the PDNG share is the duplicate population this targets.
     *
     * Scoped to `source = 'enablebanking'` so a non-banking source that happens
     * to store a `status` key in its own raw_data shape is never touched. Only
     * never-deleted rows are updated, keeping any row the user already binned.
     */
    public function up(): void
    {
        Transaction::query()
            ->where('source', TransactionSource::EnableBanking)
            ->whereNull('deleted_at')
            ->where('raw_data->status', 'PDNG')
            ->update(['deleted_at' => now()]);
    }

    public function down(): void
    {
        // One-off repair. The rows it bins are un-settled deliveries whose real
        // copy arrives (or has arrived) as BOOK; restoring them would bring the
        // duplicates back.
    }
};
