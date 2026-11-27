<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A recurring charge the user said they are dropping ("I'm dropping it"):
     * how much it took off the estimate adjustment it lowered, so Undo can
     * give it back, and what that adjustment was left at, so Undo can tell
     * whether the user changed it since. It goes with that adjustment.
     */
    public function up(): void
    {
        Schema::create('forecast_recurring_drops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('space_id')->nullable()->index();
            $table->foreignUuid('forecast_estimate_adjustment_id')->constrained()->cascadeOnDelete();
            $table->string('charge_key', 64);
            $table->bigInteger('reduction');
            $table->bigInteger('adjustment_amount');
            $table->boolean('created_adjustment')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'space_id', 'charge_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_recurring_drops');
    }
};
