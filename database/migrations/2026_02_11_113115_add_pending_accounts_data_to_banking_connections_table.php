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
            $table->json('pending_accounts_data')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('banking_connections', function (Blueprint $table) {
            $table->dropColumn('pending_accounts_data');
        });
    }
};
