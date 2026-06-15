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
        Schema::create('category_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('from_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignUuid('to_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('source');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->timestamps();

            $table->index(['source', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_corrections');
    }
};
