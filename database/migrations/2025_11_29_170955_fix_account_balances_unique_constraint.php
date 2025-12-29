<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('account_balances', function (Blueprint $table) {
            // Recreate it properly
            $table->unique(['account_id', 'balance_date'], 'account_balances_account_id_balance_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_balances', function (Blueprint $table) {
            $table->dropUnique('account_balances_account_id_balance_date_unique');
            // Restoration might be tricky if we don't know the exact broken state, but let's assume valid state
            $table->unique(['account_id', 'balance_date'], 'account_balances_account_id_balance_date_unique');
        });
    }
};
