<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a bank-sourced transaction sat on the bank's own timeline before the
     * user moved its date. The sync watermark and the derived balance history are
     * built on that timeline: moving a row forward would otherwise shrink the
     * window the next sync asks for and lose bank rows the bank delivers late.
     *
     * Null on every row that was never date-edited, which is how those rows keep
     * behaving exactly as before.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->date('original_transaction_date')->nullable()->after('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('original_transaction_date');
        });
    }
};
