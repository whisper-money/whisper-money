<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user's correction to the estimated rest of a parent category on one
     * account, over a range of months. It never touches the budget.
     */
    public function up(): void
    {
        Schema::create('forecast_estimate_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('space_id')->nullable()->index();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->bigInteger('amount');
            $table->timestamps();

            $table->index(['user_id', 'space_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_estimate_adjustments');
    }
};
