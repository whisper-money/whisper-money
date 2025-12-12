<?php

use App\Models\User;

it('redirects non-onboarded user from dashboard to onboarding', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertRedirect('/onboarding');
});

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
        'encryption_salt' => 'test-salt',
    ]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertSuccessful();
});

it('sets onboarded_at when completing onboarding', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    expect($user->isOnboarded())->toBeFalse();

    $response = $this->actingAs($user)->post('/onboarding/complete');

    $response->assertRedirect('/dashboard');

    $user->refresh();
    expect($user->isOnboarded())->toBeTrue();
    expect($user->onboarded_at)->not->toBeNull();
});

it('redirects non-onboarded user from accounts list to onboarding', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    $response = $this->actingAs($user)->get('/accounts');

    $response->assertRedirect('/onboarding');
});

it('redirects non-onboarded user from transactions to onboarding', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    $response = $this->actingAs($user)->get('/transactions');

    $response->assertRedirect('/onboarding');
});
