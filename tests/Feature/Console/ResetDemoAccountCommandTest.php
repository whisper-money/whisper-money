<?php

use App\Models\User;

beforeEach(function () {
    config(['app.demo' => [
        'email' => 'demo@whisper.money',
        'password' => 'demo',
    ]]);
});

test('demo:reset fails if demo email is not configured', function () {
    config(['app.demo.email' => null]);

    $this->artisan('demo:reset')->assertFailed();
});

test('demo:reset creates demo user with basic data structure', function () {
    $this->artisan('demo:reset')->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();

    // Verify core data exists
    expect($user)->not->toBeNull();
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->accounts()->count())->toBe(6);
    expect($user->transactions()->count())->toBeGreaterThan(2000);
    expect($user->categories()->count())->toBe(64);
})->group('slow');

test('demo:reset verifies existing unverified demo user', function () {
    User::factory()->create([
        'email' => 'demo@whisper.money',
        'email_verified_at' => null,
    ]);

    $this->artisan('demo:reset')->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();

    expect($user->email_verified_at)->not->toBeNull();
})->group('slow');
