<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('budget_period_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('budget_period_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('budget_category_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('allocated_amount')->default(0);
            $table->timestamps();

            $table->unique(['budget_period_id', 'budget_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_period_allocations');
    }
};
