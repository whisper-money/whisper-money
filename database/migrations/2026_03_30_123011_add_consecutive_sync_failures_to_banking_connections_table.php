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
            $table->unsignedTinyInteger('consecutive_sync_failures')->default(0)->after('error_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banking_connections', function (Blueprint $table) {
            $table->dropColumn('consecutive_sync_failures');
        });
    }
};
