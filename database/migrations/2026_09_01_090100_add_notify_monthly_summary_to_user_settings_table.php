<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One switch for both the monthly report and the reminder that precedes it:
     * nobody wants one without the other, and a recurring product email must not
     * be silenced by the generic drip opt-outs.
     */
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('notify_monthly_summary')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('notify_monthly_summary');
        });
    }
};
