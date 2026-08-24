<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Budget amounts are snapshots written when a transaction is assigned, so
     * every row created before the assignment weighed the account's ownership
     * share still counts a shared account at 100%. Rewrite them once here.
     *
     * The expression matches `Transaction::OWNED_AMOUNT_SQL`; kept inline so
     * this stays a self-contained one-shot fix.
     */
    public function up(): void
    {
        DB::statement('
            UPDATE budget_transactions
            JOIN transactions ON transactions.id = budget_transactions.transaction_id
            JOIN accounts ON accounts.id = transactions.account_id
            SET budget_transactions.amount = -round(transactions.amount * accounts.ownership_percentage / 100)
            WHERE accounts.ownership_percentage < 100
        ');
    }

    /**
     * Back to the full transaction amount, which is what these rows held. An
     * account moved back to 100% after `up()` ran is left alone: its rows are
     * already at face value and there is no record of the share they were
     * written with.
     */
    public function down(): void
    {
        DB::statement('
            UPDATE budget_transactions
            JOIN transactions ON transactions.id = budget_transactions.transaction_id
            JOIN accounts ON accounts.id = transactions.account_id
            SET budget_transactions.amount = -transactions.amount
            WHERE accounts.ownership_percentage < 100
        ');
    }
};
