<?php

use App\Features\AiConsentSettings;
use App\Models\User;
use Laravel\Pennant\Feature;

test('enables a feature for a percentage of users', function () {
    $users = User::factory()->count(10)->create();

    $this->artisan('feature:enable', ['feature' => 'AiConsentSettings', 'target' => '40%'])
        ->expectsOutputToContain('enabled for 4 users')
        ->assertSuccessful();

    $enabled = $users->filter(fn (User $user) => Feature::for($user)->active(AiConsentSettings::class));

    expect($enabled)->toHaveCount(4);
});

test('rejects an out-of-range percentage', function () {
    User::factory()->create();

    $this->artisan('feature:enable', ['feature' => 'AiConsentSettings', 'target' => '0%'])
        ->assertFailed();
});
