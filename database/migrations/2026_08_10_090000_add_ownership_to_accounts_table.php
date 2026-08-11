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
            // Signed on purpose: MySQL promotes `signed * unsigned` to BIGINT
            // UNSIGNED, which overflows on the negative amounts of an expense.
            $table->tinyInteger('ownership_percentage')->default(100)->after('hidden_on_dashboard');
            $table->boolean('ownership_applies_to_balance')->default(false)->after('ownership_percentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['ownership_percentage', 'ownership_applies_to_balance']);
        });
    }
};
