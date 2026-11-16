<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every user sees the cashflow Available view and the savings/investments
 * split now, so App\Features\CashflowAvailableFree no longer exists. Pennant
 * keeps every resolved assignment in the features table, which would otherwise
 * leave rows nothing can ever read again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('features')
            ->where('name', 'App\Features\CashflowAvailableFree')
            ->delete();
    }

    public function down(): void
    {
        // The assignments are gone for good; the feature they belonged to no longer exists.
    }
};
