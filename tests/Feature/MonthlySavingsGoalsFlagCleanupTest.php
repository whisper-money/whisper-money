<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the migration deletes the stored MonthlySavingsGoals assignments and nothing else', function () {
    $scope = User::class.'|'.User::factory()->create()->id;

    DB::table('features')->insert([
        ['name' => 'App\Features\MonthlySavingsGoals', 'scope' => $scope, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'App\Features\FullImport', 'scope' => $scope, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration = require database_path('migrations/2026_10_21_103443_delete_monthly_savings_goals_feature_assignments.php');
    $migration->up();

    expect(DB::table('features')->pluck('name')->all())->toBe(['App\Features\FullImport']);
});
