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
            // Nullable on purpose: null means "we never asked the provider",
            // which is what every row created before this column looks like
            // until banking:backfill-aspsp-beta has run over it, and what a
            // provider that has no notion of beta connectors stays as.
            $table->boolean('aspsp_beta')->nullable()->after('aspsp_logo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banking_connections', function (Blueprint $table) {
            $table->dropColumn('aspsp_beta');
        });
    }
};
