<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('dedup_fingerprint', 80)->nullable()->after('external_transaction_id');
            $table->unique(['account_id', 'dedup_fingerprint'], 'transactions_account_fp_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_account_fp_unique');
            $table->dropColumn('dedup_fingerprint');
        });
    }
};
