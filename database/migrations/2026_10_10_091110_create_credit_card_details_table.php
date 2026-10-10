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
        Schema::create('credit_card_details', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUuid('account_id')->unique()->constrained()->onDelete('cascade');
            $table->date('statement_closing_date');
            $table->date('payment_due_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_card_details');
    }
};
