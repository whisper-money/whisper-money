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
            $table->boolean('close_to_limit_notified')->default(false);
            $table->boolean('over_limit_notified')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budget_periods', function (Blueprint $table) {
            $table->dropColumn(['close_to_limit_notified', 'over_limit_notified']);
        });
    }
};
