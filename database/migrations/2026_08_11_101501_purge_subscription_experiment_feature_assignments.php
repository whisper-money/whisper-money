<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The trial experiment ended on the control variant, so App\Features\SubscriptionExperiment
 * no longer exists. Pennant keeps every resolved assignment in the features table,
 * which would otherwise leave thousands of rows nothing can ever read again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('features')
            ->where('name', 'App\Features\SubscriptionExperiment')
            ->delete();
    }

    public function down(): void
    {
        // The assignments are gone for good; the feature they belonged to no longer exists.
    }
};
