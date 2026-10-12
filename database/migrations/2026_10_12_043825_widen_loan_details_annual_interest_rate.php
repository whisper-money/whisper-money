<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Validation and the MCP tools accept rates from 0 to 100, but decimal(5, 3)
     * tops out at 99.999, so a loan at exactly 100% failed on insert. One more
     * integer digit lets the column hold the whole documented range.
     */
    public function up(): void
    {
        Schema::table('loan_details', function (Blueprint $table) {
            $table->decimal('annual_interest_rate', 6, 3)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_details', function (Blueprint $table) {
            $table->decimal('annual_interest_rate', 5, 3)->change();
        });
    }
};
