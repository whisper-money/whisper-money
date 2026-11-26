<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A suggestion the user turned down, remembered by its description
     * fingerprint so the detectors never propose it again.
     */
    public function up(): void
    {
        Schema::create('forecast_dismissals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('space_id')->nullable()->index();
            $table->string('fingerprint');
            $table->string('kind');
            $table->timestamps();

            $table->unique(['user_id', 'space_id', 'fingerprint', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_dismissals');
    }
};
