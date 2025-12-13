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
        Schema::create('label_transaction', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('label_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['label_id', 'transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('label_transaction');
    }
};
