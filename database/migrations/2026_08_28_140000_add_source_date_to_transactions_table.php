<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The date the source gave a transaction - the bank that synced it or the
     * file it was imported from - kept once the user moves it onto another day.
     *
     * `transaction_date` answers "which month does this count towards", which is
     * the user's call. This answers "when does the source say it happened", which
     * is not. The sync watermark and the derived balance history are built on the
     * second: moving a row forward would otherwise shrink the window the next sync
     * asks for and lose bank rows the bank delivers late.
     *
     * Null while the two answers are the same, and on manual rows, which have no
     * source timeline to preserve.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->date('source_date')->nullable()->after('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('source_date');
        });
    }
};
