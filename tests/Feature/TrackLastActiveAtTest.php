<?php

use App\Models\User;

test('an authenticated request records the last active date', function () {
    $user = User::factory()->onboarded()->create(['last_active_at' => null]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->fresh()->last_active_at)->not->toBeNull();
});

test('the last active date is not updated again within the throttle window', function () {
    $recent = now()->subMinute();
    $user = User::factory()->onboarded()->create(['last_active_at' => $recent]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->fresh()->last_active_at->timestamp)->toBe($recent->timestamp);
});

test('the last active date is refreshed once the throttle window passes', function () {
    $stale = now()->subHour();
    $user = User::factory()->onboarded()->create(['last_active_at' => $stale]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->fresh()->last_active_at->timestamp)->toBeGreaterThan($stale->timestamp);
});
