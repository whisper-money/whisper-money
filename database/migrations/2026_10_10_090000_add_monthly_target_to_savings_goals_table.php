<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A monthly goal is a variant of a savings goal rather than an entity of its
     * own: it keeps the label, the archive and the planning card, and swaps the
     * one-off target for a target that starts over every calendar month.
     */
    public function up(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->string('kind')->default('one_off')->after('name');
            $table->string('monthly_target_type')->nullable()->after('target_date');
            $table->bigInteger('monthly_target_amount')->nullable()->after('monthly_target_type');
            $table->decimal('monthly_target_rate', 5, 2)->nullable()->after('monthly_target_amount');
            $table->boolean('notify_on_month_end_reminder')->default(true)->after('monthly_target_rate');
        });
    }

    public function down(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dropColumn([
                'kind',
                'monthly_target_type',
                'monthly_target_amount',
                'monthly_target_rate',
                'notify_on_month_end_reminder',
            ]);
        });
    }
};
