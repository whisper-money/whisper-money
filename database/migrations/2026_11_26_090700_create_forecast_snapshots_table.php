<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The plan of a month, frozen when the month opens, that "expected vs
     * actual" compares against.
     */
    public function up(): void
    {
        Schema::create('forecast_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('space_id')->nullable()->index();
            $table->date('month');
            $table->json('payload');
            $table->timestamp('frozen_at');
            $table->timestamps();

            $table->unique(['user_id', 'space_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_snapshots');
    }
};
