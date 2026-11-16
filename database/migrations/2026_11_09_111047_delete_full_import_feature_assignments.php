<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The full import opens only through `full_import.enabled`, inside the
 * onboarding window, with no flag to extend it, so App\Features\FullImport no
 * longer exists. Pennant keeps every resolved assignment in the features table,
 * which would otherwise leave rows nothing can ever read again. The
 * CashflowAvailableFree assignments stay: that flag is still in use here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('features')
            ->where('name', 'App\Features\FullImport')
            ->delete();
    }

    public function down(): void
    {
        // The assignments are gone for good; the feature they belonged to no longer exists.
    }
};
