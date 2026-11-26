<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How an account takes part in the forecast: whether it is included, the
     * balance the user wants to keep in it, and the yearly growth rate (in
     * basis points) of an investment or property.
     */
    public function up(): void
    {
        Schema::create('forecast_account_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('included')->default(true);
            $table->bigInteger('minimum_balance')->nullable();
            $table->integer('annual_rate_bps')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_account_settings');
    }
};
