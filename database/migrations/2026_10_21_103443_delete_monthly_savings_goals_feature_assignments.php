<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every user can create monthly savings goals now, so
 * App\Features\MonthlySavingsGoals no longer exists. Pennant keeps every
 * resolved assignment in the features table, which would otherwise leave rows
 * nothing can ever read again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('features')
            ->where('name', 'App\Features\MonthlySavingsGoals')
            ->delete();
    }

    public function down(): void
    {
        // The assignments are gone for good; the feature they belonged to no longer exists.
    }
};
