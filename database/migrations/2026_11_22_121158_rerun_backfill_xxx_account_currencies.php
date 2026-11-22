<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Re-run the "XXX" currency backfill for rows written after it first ran.
     *
     * Linking a bank account from the manage-accounts page kept persisting the
     * ISO 4217 "no currency" placeholder until it went through
     * resolveImportedCurrency() too. The original backfill only touches rows
     * still on "XXX", so running it again is idempotent.
     */
    public function up(): void
    {
        (require database_path('migrations/2026_06_27_000000_backfill_xxx_account_currencies.php'))->up();
    }

    public function down(): void
    {
        // Irreversible: the original "XXX" carried no real currency.
    }
};
