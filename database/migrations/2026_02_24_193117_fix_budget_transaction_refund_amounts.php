<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix budget_transactions where the original transaction was a refund (positive amount).
     * These were incorrectly stored as positive via abs() — they should be negative
     * to correctly reduce the budget's cumulative spending.
     */
    public function up(): void
    {
        DB::statement('
            UPDATE budget_transactions
            SET amount = -amount
            WHERE transaction_id IN (
                SELECT id FROM transactions WHERE amount > 0
            )
        ');
    }

    /**
     * Revert: make all budget_transaction amounts positive again (original behavior).
     */
    public function down(): void
    {
        DB::statement('
            UPDATE budget_transactions
            SET amount = ABS(amount)
        ');
    }
};
