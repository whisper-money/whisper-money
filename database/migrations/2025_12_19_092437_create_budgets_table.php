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
        Schema::create('budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('period_type');
            $table->integer('period_duration')->nullable();
            $table->integer('period_start_day')->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('label_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rollover_type')->default('carry_over');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
