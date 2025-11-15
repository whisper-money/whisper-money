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
        Schema::create('account_balances', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            $table->date('balance_date');
            $table->bigInteger('balance');
            $table->timestamps();

            $table->unique(['account_id', 'balance_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_balances');
    }
};
