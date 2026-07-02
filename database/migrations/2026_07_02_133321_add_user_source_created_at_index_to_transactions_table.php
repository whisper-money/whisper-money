<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Support the daily "transactions synced" email query, which filters
     * transactions by (user_id = ?, source = ?, created_at > ?). The existing
     * indexes only lead with user_id via transaction_date, so that query scans
     * every one of the user's rows to filter on source + created_at
     * (PHP-LARAVEL-3X). This composite index matches the two equalities and the
     * range directly.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'source', 'created_at'], 'idx_transactions_user_source_created');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_transactions_user_source_created');
        });
    }
};
