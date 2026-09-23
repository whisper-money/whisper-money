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
        Schema::table('banking_connections', function (Blueprint $table) {
            // How far the provider's ledger has been counted into the invested
            // amount (Kraken), so a sync only fetches the entries after it.
            // Null means "walk the whole ledger".
            $table->timestamp('ledger_synced_until')->nullable()->after('last_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banking_connections', function (Blueprint $table) {
            $table->dropColumn('ledger_synced_until');
        });
    }
};
