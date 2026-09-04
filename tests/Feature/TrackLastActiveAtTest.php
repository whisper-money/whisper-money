<?php

use App\Models\User;

beforeEach(function (): void {
    // These assertions are about what the middleware wrote, never about the
    // HTML a server-side render would produce. Left on, Inertia posts the page
    // to its SSR gateway and the suite's stray-request guard fails the test.
    config()->set('inertia.ssr.enabled', false);
});

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

/*
 * The visit streak rides on the same write: there is no log of past visits, so
 * the run is carried forward one day at a time from the timestamp that was
 * already there.
 */

test('a visit the next day extends the streak', function () {
    $user = User::factory()->onboarded()->create([
        'last_active_at' => now()->subDay(),
        'visit_streak' => 4,
        'longest_visit_streak' => 4,
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->fresh()->visit_streak)->toBe(5)
        ->and($user->fresh()->longest_visit_streak)->toBe(5);
});

test('a day missed starts the streak over but keeps the best run', function () {
    $user = User::factory()->onboarded()->create([
        'last_active_at' => now()->subDays(3),
        'visit_streak' => 9,
        'longest_visit_streak' => 9,
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->fresh()->visit_streak)->toBe(1)
        ->and($user->fresh()->longest_visit_streak)->toBe(9);
});

test('a second visit the same day leaves the streak where it is', function () {
    $user = User::factory()->onboarded()->create([
        'last_active_at' => now()->subHour(),
        'visit_streak' => 6,
        'longest_visit_streak' => 6,
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->fresh()->visit_streak)->toBe(6);
});

test('a visit just after midnight counts as a new day, throttle or not', function () {
    $this->travelTo('2026-09-20 00:00:30');

    $user = User::factory()->onboarded()->create([
        'last_active_at' => '2026-09-19 23:59:50',
        'visit_streak' => 2,
        'longest_visit_streak' => 2,
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->fresh()->visit_streak)->toBe(3);
});

test('the day the streak turns on is the reader\'s own', function () {
    $this->travelTo('2026-09-20 22:30:00');   // already the 21st in Madrid

    $user = User::factory()->onboarded()->create([
        'timezone' => 'Europe/Madrid',
        'last_active_at' => '2026-09-20 08:00:00',   // the 20th, there and here
        'visit_streak' => 1,
        'longest_visit_streak' => 1,
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->fresh()->visit_streak)->toBe(2);
});

test('a visit the next week extends the weekly streak while the daily one restarts', function () {
    $user = User::factory()->onboarded()->create([
        'last_active_at' => now()->subWeek(),
        'visit_streak' => 3,
        'longest_visit_streak' => 3,
        'visit_week_streak' => 8,
        'longest_visit_week_streak' => 8,
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->fresh()->visit_streak)->toBe(1)
        ->and($user->fresh()->longest_visit_streak)->toBe(3)
        ->and($user->fresh()->visit_week_streak)->toBe(9);
});

test('a week missed starts the weekly streak over but keeps the best run', function () {
    $user = User::factory()->onboarded()->create([
        'last_active_at' => now()->subWeeks(3),
        'visit_week_streak' => 7,
        'longest_visit_week_streak' => 7,
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->fresh()->visit_week_streak)->toBe(1)
        ->and($user->fresh()->longest_visit_week_streak)->toBe(7);
});

test('another day of the same week leaves the weekly streak where it is', function () {
    $this->travelTo('2026-09-16 10:00:00');   // a Wednesday

    $user = User::factory()->onboarded()->create([
        'last_active_at' => '2026-09-14 10:00:00',   // the Monday before it
        'visit_week_streak' => 5,
        'longest_visit_week_streak' => 5,
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->fresh()->visit_week_streak)->toBe(5)
        ->and($user->fresh()->visit_streak)->toBe(1);
});
