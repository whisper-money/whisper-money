<?php

use App\Models\User;

it('redirects non-onboarded user to onboarding', function (string $path) {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $response = $this->actingAs($user)->get($path);

    $response->assertRedirect('/onboarding');
})->with([
    'dashboard' => '/dashboard',
    'accounts list' => '/accounts',
    'transactions' => '/transactions',
]);

it('allows onboarded user to access dashboard', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertSuccessful();
});

it('redirects onboarded user away from onboarding page', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertRedirect('/dashboard');
});

it('allows non-onboarded user to access onboarding page', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertSuccessful();
});

it('sets onboarded_at when completing onboarding', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    expect($user->isOnboarded())->toBeFalse();

    $response = $this->actingAs($user)->post('/onboarding/complete');

    $response->assertRedirect('/dashboard');

    $user->refresh();
    expect($user->isOnboarded())->toBeTrue();
    expect($user->onboarded_at)->not->toBeNull();
});
