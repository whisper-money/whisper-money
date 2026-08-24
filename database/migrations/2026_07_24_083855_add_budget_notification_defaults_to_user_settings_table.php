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
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('budget_notify_on_new_transaction')->default(false);
            $table->boolean('budget_notify_on_close_to_limit')->default(false);
            $table->boolean('budget_notify_on_over_limit')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn([
                'budget_notify_on_new_transaction',
                'budget_notify_on_close_to_limit',
                'budget_notify_on_over_limit',
            ]);
        });
    }
};
