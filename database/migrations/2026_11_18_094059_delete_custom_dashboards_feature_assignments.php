<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Custom dashboards are out for everyone, and App\Features\CustomDashboards,
 * the flag that hid their UI, is gone with it. Pennant keeps every resolved
 * assignment in the features table, which would otherwise leave rows nothing
 * can ever read again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('features')
            ->where('name', 'App\Features\CustomDashboards')
            ->delete();
    }

    public function down(): void
    {
        // The assignments are gone for good; the feature they belonged to no longer exists.
    }
};
