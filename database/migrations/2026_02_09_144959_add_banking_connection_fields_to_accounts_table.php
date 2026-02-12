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
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignUuid('banking_connection_id')->nullable()->after('type')->constrained()->nullOnDelete();
            $table->string('external_account_id')->nullable()->after('banking_connection_id');

            $table->index('banking_connection_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['banking_connection_id']);
            $table->dropColumn(['banking_connection_id', 'external_account_id']);
        });
    }
};
