<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks the rows the backwards balance walk produced itself, so a later run
     * may correct them while leaving every other row alone.
     *
     * Rows that predate this column default to false on purpose: their author is
     * unknowable, and guessing wrong would overwrite a figure the bank or the
     * user gave us. No backfill.
     */
    public function up(): void
    {
        Schema::table('account_balances', function (Blueprint $table) {
            $table->boolean('derived')->default(false)->after('invested_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_balances', function (Blueprint $table) {
            $table->dropColumn('derived');
        });
    }
};
