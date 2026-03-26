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
        Schema::create('loan_details', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUuid('account_id')->unique()->constrained()->onDelete('cascade');
            $table->decimal('annual_interest_rate', 5, 3);
            $table->integer('loan_term_months');
            $table->date('start_date');
            $table->bigInteger('original_amount');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_details');
    }
};
