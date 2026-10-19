<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the month's close was announced in the bell. Kept apart from
     * `closed_at` so a notice that fails after the close is retried on the next
     * run instead of being lost with it.
     */
    public function up(): void
    {
        Schema::table('savings_goal_periods', function (Blueprint $table) {
            $table->timestamp('closed_notified_at')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('savings_goal_periods', function (Blueprint $table) {
            $table->dropColumn('closed_notified_at');
        });
    }
};
