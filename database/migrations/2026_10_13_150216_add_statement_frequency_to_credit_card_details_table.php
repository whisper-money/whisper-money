<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A statement can now repeat weekly as well as monthly. Every card stored
     * so far repeats monthly.
     */
    public function up(): void
    {
        Schema::table('credit_card_details', function (Blueprint $table) {
            $table->string('statement_frequency')->default('monthly')->after('payment_due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_card_details', function (Blueprint $table) {
            $table->dropColumn('statement_frequency');
        });
    }
};
