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
        Schema::table('budget_periods', function (Blueprint $table) {
            $table->boolean('processing_historical')->default(false)->after('allocated_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budget_periods', function (Blueprint $table) {
            $table->dropColumn('processing_historical');
        });
    }
};
