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
        // Add composite index on transactions to speed up historical query
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'transaction_date', 'category_id'], 'idx_transactions_budget_lookup');
        });

        // Add unique constraint on budget_transactions to prevent duplicates
        Schema::table('budget_transactions', function (Blueprint $table) {
            $table->unique(['transaction_id', 'budget_period_id'], 'uq_budget_transaction_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_transactions_budget_lookup');
        });

        Schema::table('budget_transactions', function (Blueprint $table) {
            $table->dropUnique('uq_budget_transaction_period');
        });
    }
};
