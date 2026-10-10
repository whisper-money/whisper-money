<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a new monthly savings goal starts with for its month-end reminder.
     * On by default: the reminder is the point of a monthly goal.
     */
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('savings_goal_notify_on_month_end_reminder')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('savings_goal_notify_on_month_end_reminder');
        });
    }
};
