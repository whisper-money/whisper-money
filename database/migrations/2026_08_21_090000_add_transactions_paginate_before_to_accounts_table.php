<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Where a sync run stopped paginating the bank's history because it
            // ran out of its page budget, so the next run can pick the history
            // up from there. Null means nothing is owed - which is what every
            // existing account is, since none of them has ever been cut short.
            $table->date('transactions_paginate_before')->nullable()->after('external_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('transactions_paginate_before');
        });
    }
};
