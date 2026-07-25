<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The previous migration turned every budget notification on by default. The
     * per-transaction notice is too noisy for that: it emails on every single
     * transaction assigned to a budget, and a catch-all budget matches all of
     * them. Put it back to opt-in — column default plus a one-off reset of the
     * rows the force-enable touched — and leave the limit alerts on.
     *
     * Uses the query builder so `updated_at` is left alone, same as the
     * force-enable did.
     */
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('budget_notify_on_new_transaction')->default(false)->change();
        });

        DB::table('user_settings')->update(['budget_notify_on_new_transaction' => false]);

        DB::table('budgets')->update(['notify_on_new_transaction' => false]);
    }

    /**
     * Restores the column default only; the reset above is not reversible.
     */
    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('budget_notify_on_new_transaction')->default(true)->change();
        });
    }
};
