<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per calendar month of a monthly savings goal. It freezes the target
     * that was in force that month; what was saved is never stored, it is always
     * summed live from the goal's tagged transactions.
     */
    public function up(): void
    {
        Schema::create('savings_goal_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('savings_goal_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->string('target_type');
            $table->bigInteger('target_amount')->nullable();
            $table->decimal('target_rate', 5, 2)->nullable();
            $table->bigInteger('resolved_target_amount')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reminder_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['savings_goal_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_goal_periods');
    }
};
