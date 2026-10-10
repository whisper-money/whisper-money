<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A card can now carry a credit limit without statement dates, so the
     * dates become optional.
     */
    public function up(): void
    {
        Schema::table('credit_card_details', function (Blueprint $table) {
            $table->date('statement_closing_date')->nullable()->change();
            $table->date('payment_due_date')->nullable()->change();
            $table->bigInteger('credit_limit')->nullable()->after('payment_due_date');
        });
    }

    /**
     * Reverse the migrations.
     *
     * A row without dates cannot survive the NOT NULL columns, and without
     * the limit it carries nothing else.
     */
    public function down(): void
    {
        DB::table('credit_card_details')
            ->whereNull('statement_closing_date')
            ->orWhereNull('payment_due_date')
            ->delete();

        Schema::table('credit_card_details', function (Blueprint $table) {
            $table->dropColumn('credit_limit');
            $table->date('statement_closing_date')->nullable(false)->change();
            $table->date('payment_due_date')->nullable(false)->change();
        });
    }
};
