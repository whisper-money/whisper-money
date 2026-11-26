<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The signed amount of a forecast line from a date on. "From this month"
     * edits add a version instead of rewriting the past.
     */
    public function up(): void
    {
        Schema::create('forecast_item_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('forecast_item_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->bigInteger('amount');
            $table->timestamps();

            $table->unique(['forecast_item_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_item_versions');
    }
};
