<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A transaction the user said was a one-off, kept out of the median the
     * estimated spend is worked out from.
     */
    public function up(): void
    {
        Schema::create('forecast_excluded_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_excluded_transactions');
    }
};
