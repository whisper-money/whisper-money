<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the migration deletes the stored CalculateBalancesOnImport assignments and nothing else', function () {
    $scope = User::class.'|'.User::factory()->create()->id;

    DB::table('features')->insert([
        ['name' => 'App\Features\CalculateBalancesOnImport', 'scope' => $scope, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'App\Features\OnboardingGateCopy', 'scope' => $scope, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration = require database_path('migrations/2026_11_09_121545_delete_calculate_balances_on_import_feature_assignments.php');
    $migration->up();

    expect(DB::table('features')->pluck('name')->all())->toBe(['App\Features\OnboardingGateCopy']);
});
