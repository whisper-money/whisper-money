<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the migration deletes the stored FullImport assignments and nothing else', function () {
    $scope = User::class.'|'.User::factory()->create()->id;

    DB::table('features')->insert([
        ['name' => 'App\Features\FullImport', 'scope' => $scope, 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'App\Features\RecurringTransactions', 'scope' => $scope, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'App\Features\CustomDashboards', 'scope' => $scope, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration = require database_path('migrations/2026_11_09_111047_delete_full_import_feature_assignments.php');
    $migration->up();

    expect(DB::table('features')->orderBy('name')->pluck('name')->all())
        ->toBe(['App\Features\CustomDashboards', 'App\Features\RecurringTransactions']);
});
