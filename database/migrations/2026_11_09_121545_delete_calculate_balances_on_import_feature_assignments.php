<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Working balances out from the transactions on a CSV import is gone, and
 * App\Features\CalculateBalancesOnImport, the flag that gated it, with it.
 * Pennant keeps every resolved assignment in the features table, which would
 * otherwise leave rows nothing can ever read again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('features')
            ->where('name', 'App\Features\CalculateBalancesOnImport')
            ->delete();
    }

    public function down(): void
    {
        // The assignments are gone for good; the feature they belonged to no longer exists.
    }
};
