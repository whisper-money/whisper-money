<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a user answered the forecast's review in a space, so the page
     * stops opening it on its own and asking for it: answering it no longer
     * has to add a line.
     */
    public function up(): void
    {
        Schema::create('forecast_review_completions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('space_id')->nullable()->index();
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique(['user_id', 'space_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_review_completions');
    }
};
