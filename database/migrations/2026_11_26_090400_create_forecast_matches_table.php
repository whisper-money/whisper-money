<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A transaction paired with one occurrence of a forecast line: the base of
     * "expected vs actual". A transaction pays at most one occurrence.
     */
    public function up(): void
    {
        Schema::create('forecast_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('forecast_item_id')->constrained()->cascadeOnDelete();
            $table->date('occurrence_date');
            $table->foreignUuid('transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('matched_by');
            // Actual minus expected amount, in minor units.
            $table->bigInteger('deviation')->default(0);
            $table->timestamps();

            $table->index(['forecast_item_id', 'occurrence_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_matches');
    }
};
