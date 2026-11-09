<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every user sees the cashflow Available view now, and the full import is
 * open to anyone inside the onboarding window with no way to extend it, so
 * App\Features\CashflowAvailableFree and App\Features\FullImport no longer
 * exist. Pennant keeps every resolved assignment in the features table, which
 * would otherwise leave rows nothing can ever read again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('features')
            ->whereIn('name', ['App\Features\CashflowAvailableFree', 'App\Features\FullImport'])
            ->delete();
    }

    public function down(): void
    {
        // The assignments are gone for good; the features they belonged to no longer exist.
    }
};
