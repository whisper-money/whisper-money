<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the migration deletes the stored CashflowAvailableFree assignments and nothing else', function () {
    $scope = User::class.'|'.User::factory()->create()->id;

    DB::table('features')->insert([
        ['name' => 'App\Features\CashflowAvailableFree', 'scope' => $scope, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'App\Features\CustomDashboards', 'scope' => $scope, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration = require database_path('migrations/2026_11_16_213854_delete_cashflow_available_free_feature_assignments.php');
    $migration->up();

    expect(DB::table('features')->pluck('name')->all())
        ->toBe(['App\Features\CustomDashboards']);
});
