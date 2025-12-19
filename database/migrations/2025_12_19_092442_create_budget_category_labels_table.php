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
        Schema::create('budget_category_labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('budget_category_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('label_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['budget_category_id', 'label_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_category_labels');
    }
};
