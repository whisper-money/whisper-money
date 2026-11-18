<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the migration deletes the stored CustomDashboards assignments, guest scope included, and nothing else', function () {
    $scope = User::class.'|'.User::factory()->create()->id;

    DB::table('features')->insert([
        ['name' => 'App\Features\CustomDashboards', 'scope' => $scope, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'App\Features\CustomDashboards', 'scope' => '__laravel_null', 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'App\Features\OnboardingGateCopy', 'scope' => $scope, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration = require database_path('migrations/2026_11_18_094059_delete_custom_dashboards_feature_assignments.php');
    $migration->up();

    expect(DB::table('features')->pluck('name')->all())->toBe(['App\Features\OnboardingGateCopy']);
});
