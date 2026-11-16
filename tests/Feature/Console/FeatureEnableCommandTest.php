<?php

use App\Features\CustomDashboards;
use App\Models\User;
use Laravel\Pennant\Feature;
use Tests\Support\SampleRolloutFeature;

beforeEach(function () {
    SampleRolloutFeature::register();
});

test('enables a feature for a percentage of users', function () {
    $users = User::factory()->count(10)->create();

    $this->artisan('feature:enable', ['feature' => SampleRolloutFeature::NAME, 'target' => '40%'])
        ->expectsOutputToContain('enabled for 4 users')
        ->assertSuccessful();

    $enabled = $users->filter(fn (User $user) => Feature::for($user)->active(SampleRolloutFeature::CLASS_NAME));

    expect($enabled)->toHaveCount(4);
});

test('rejects an out-of-range percentage', function () {
    User::factory()->create();

    $this->artisan('feature:enable', ['feature' => SampleRolloutFeature::NAME, 'target' => '0%'])
        ->assertFailed();
});

test('rejects a feature that does not exist', function () {
    $this->artisan('feature:enable', ['feature' => 'NoSuchFeature', 'target' => 'all'])
        ->expectsOutputToContain("Feature 'NoSuchFeature' not found.")
        ->assertFailed();
});

test('turns custom dashboards on for one user by email', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    expect(Feature::for($user)->active(CustomDashboards::class))->toBeFalse();

    $this->artisan('feature:enable', ['feature' => 'CustomDashboards', 'target' => $user->email])
        ->assertSuccessful();

    expect(Feature::for($user)->active(CustomDashboards::class))->toBeTrue()
        ->and(Feature::for($other)->active(CustomDashboards::class))->toBeFalse();
});
