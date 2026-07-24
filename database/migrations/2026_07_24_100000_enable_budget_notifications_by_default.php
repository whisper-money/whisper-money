<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Budget email notifications shipped opt-in (default false). Product decision
     * is to have them on by default, so flip the `user_settings` column defaults
     * and turn every existing budget and setting on. Budgets are seeded from the
     * user's `user_settings` defaults at creation, so the `budgets` columns keep
     * their own default and only need the one-off backfill here.
     */
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('budget_notify_on_new_transaction')->default(true)->change();
            $table->boolean('budget_notify_on_close_to_limit')->default(true)->change();
            $table->boolean('budget_notify_on_over_limit')->default(true)->change();
        });

        DB::table('user_settings')->update([
            'budget_notify_on_new_transaction' => true,
            'budget_notify_on_close_to_limit' => true,
            'budget_notify_on_over_limit' => true,
        ]);

        DB::table('budgets')->update([
            'notify_on_new_transaction' => true,
            'notify_on_close_to_limit' => true,
            'notify_on_over_limit' => true,
        ]);
    }

    /**
     * Restores the column defaults only. The one-off backfill above is not
     * reversible (the pre-flip per-row values are unrecoverable), so rolling
     * back leaves existing budgets and settings with notifications still on.
     */
    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('budget_notify_on_new_transaction')->default(false)->change();
            $table->boolean('budget_notify_on_close_to_limit')->default(false)->change();
            $table->boolean('budget_notify_on_over_limit')->default(false)->change();
        });
    }
};
