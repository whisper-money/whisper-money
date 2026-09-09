<?php

use App\Models\Achievement;
use App\Models\User;
use App\Notifications\AchievementUnlocked;
use Illuminate\Support\Facades\Notification;

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

/*
 * The medal a run reaches is settled here, on the request that moves the run,
 * rather than by the nightly sweep. Everything else still waits for the sweep:
 * a closed month cannot be judged mid-month.
 */

/**
 * A reader the sweep has already been through, which is what the visit award
 * asks for: their first pass is the one that reads a whole life and announces
 * itself with a single welcome row.
 */
function sweptReader(array $attributes = []): User
{
    config()->set('achievements.enabled', true);

    $user = User::factory()->onboarded()->create($attributes);

    Achievement::factory()->key('transactions.1')->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    return $user;
}

test('a run reaching a rung is awarded on the request that reaches it', function () {
    Notification::fake();

    // Two days behind, so today's visit is the third in a row.
    $user = sweptReader([
        'last_active_at' => now()->subDay(),
        'visit_streak' => 2,
        'longest_visit_streak' => 2,
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->achievements()->pluck('key')->all())->toContain('visits.1');
    Notification::assertSentTo($user, AchievementUnlocked::class);
});

test('the medal is on the shelf before the page that earned it is drawn', function () {
    // The whole point of doing this before the response rather than after it:
    // the props the header is drawn with are the ones that carry the medal.
    $user = sweptReader([
        'last_active_at' => now()->subDay(),
        'visit_streak' => 2,
        'longest_visit_streak' => 2,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('challenges.visit_streak', 3)
            ->where('challenges.unlocked.key', 'visits.1'));
});

test('a reader the sweep has never been through is left to it', function () {
    Notification::fake();
    config()->set('achievements.enabled', true);

    // No medals at all: the first sweep reads their whole life and says so in
    // one row, and taking the first medal here would turn that into a pile.
    $user = User::factory()->onboarded()->create([
        'last_active_at' => now()->subDay(),
        'visit_streak' => 2,
        'longest_visit_streak' => 2,
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->achievements()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('a run that has not moved is not awarded again', function () {
    Notification::fake();

    // Already past the three-day rung and already holding it: a second visit
    // the same day, in a week already counted, has nothing new to say.
    $user = sweptReader([
        'last_active_at' => now()->subHour(),
        'visit_streak' => 9,
        'longest_visit_streak' => 9,
        'visit_week_streak' => 3,
        'longest_visit_week_streak' => 3,
    ]);

    Achievement::factory()->key('visits.1')->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->achievements()->where('key', 'visits.1')->count())->toBe(1);
    Notification::assertNothingSent();
});
