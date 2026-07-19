<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-line labels for split transactions. Mirrors label_transaction: when a
     * transaction is split its labels live on the lines, not the transaction.
     */
    public function up(): void
    {
        Schema::create('label_split', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('label_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transaction_split_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['label_id', 'transaction_split_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_split');
    }
};
