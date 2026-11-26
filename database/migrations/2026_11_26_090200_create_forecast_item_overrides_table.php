<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A "this month only" exception to one occurrence of a forecast line: a
     * different amount, another date, or skipped altogether. `occurrence_date`
     * is the date the schedule gives the occurrence, before any move.
     */
    public function up(): void
    {
        Schema::create('forecast_item_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('forecast_item_id')->constrained()->cascadeOnDelete();
            $table->date('occurrence_date');
            $table->bigInteger('amount')->nullable();
            $table->date('moved_to')->nullable();
            $table->boolean('skipped')->default(false);
            $table->timestamps();

            $table->unique(['forecast_item_id', 'occurrence_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_item_overrides');
    }
};
