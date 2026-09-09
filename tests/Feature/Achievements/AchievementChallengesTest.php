<?php

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Achievement;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

/*
 * The two challenges the chrome carries on every screen: the visit streak in
 * the header, and what is left to categorize this month.
 *
 * Both ride along with every render, so the shape matters as much as the
 * numbers: the pill counts the run as it stands today, the bar under the medal
 * is measured on the longest run the way the progress screen measures it, and a
 * reader past the last rung gets no medal to aim at rather than a bar stuck at
 * full.
 */

beforeEach(function (): void {
    Cache::flush();
    // Protected on the TestCase, so it has to be switched off from here rather
    // than from the helper below.
    $this->withoutVite();
    // Same reason as the progress screen's own suite: left on, Inertia posts
    // every page to its SSR gateway and the stray-request guard fails the test
    // for something that has nothing to do with the props.
    config()->set('inertia.ssr.enabled', false);
});

/**
 * A reader whose visit run is exactly what the test says it is. `last_active_at`
 * is set to now so `TrackLastActiveAt` leaves the run alone: a null column reads
 * as a first visit ever and resets it to one.
 */
function challenged(array $attributes = []): User
{
    return User::factory()->onboarded()->create([
        'currency_code' => 'EUR',
        'locale' => 'en',
        'last_active_at' => now(),
        'visit_streak' => 1,
        'longest_visit_streak' => 1,
        'visit_week_streak' => 1,
        'longest_visit_week_streak' => 1,
        ...$attributes,
    ]);
}

/**
 * The shared challenges prop, as the reader's next page render sends it.
 *
 * @return array<string, mixed>|null
 */
function challengesFor(User $user): ?array
{
    $props = [];

    test()->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    return $props['challenges'] ?? null;
}

function earn(User $user, string ...$keys): void
{
    foreach ($keys as $key) {
        Achievement::factory()->key($key)->create([
            'user_id' => $user->id,
            'space_id' => $user->activeSpace()->id,
        ]);
    }
}

/**
 * Transactions in one month, categorized or not. Dated four days in, because
 * subtracting whole months from a 31st slides into the wrong one.
 */
function recordFor(User $user, int $count, int $monthsAgo, bool $categorized = false): void
{
    $space = $user->activeSpace();

    $account = $user->accounts()->first() ?? Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
        'currency_code' => 'EUR',
    ]);

    Transaction::factory()->count($count)->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
        'account_id' => $account->id,
        'category_id' => $categorized ? Category::query()->firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Groceries'],
            ['space_id' => $space->id, 'type' => CategoryType::Expense],
        )->id : null,
        'transaction_date' => now()->startOfMonth()->subMonths($monthsAgo)->addDays(4),
        'currency_code' => 'EUR',
    ]);
}

it('counts the run as it stands today, and measures the medal on the longest one', function (): void {
    // Twelve days once, five days now: the pill has to say five — there is
    // nothing to lose about a run that already broke — while the bar keeps
    // reading the peak, exactly as the progress screen does.
    $user = challenged(['visit_streak' => 5, 'longest_visit_streak' => 12]);
    earn($user, 'visits.1', 'visits.2');

    $challenges = challengesFor($user);

    expect($challenges['visit_streak'])->toBe(5);

    $visits = collect($challenges['medals'])->firstWhere('track', 'visits');

    expect($visits['name'])->toBe('Visit streak')
        ->and($visits['icon'])->toBe('calendar-days')
        ->and($visits['rarity'])->toBe('uncommon')
        ->and($visits['figure'])->toBe(['type' => 'days', 'value' => 30, 'currency' => null])
        ->and($visits['progress'])->toBe(['now' => 12, 'goal' => 30, 'unlocking' => false]);
});

it('sends both visit tracks, in the order the panel draws them', function (): void {
    $tracks = collect(challengesFor(challenged())['medals'])->pluck('track');

    expect($tracks->all())->toBe(['visits', 'visit_weeks']);
});

it('draws a run of zero without pretending it is one', function (): void {
    // A reader who has never been counted: the payload says zero and the pill
    // is not drawn at all.
    $user = challenged(['visit_streak' => 0, 'longest_visit_streak' => 0, 'last_active_at' => now()]);

    expect(challengesFor($user)['visit_streak'])->toBe(0);
});

it('says a medal is unlocking once the reader stands on its rung', function (): void {
    // Past day 7 with `visits.1` in hand, so the next rung is `visits.2` and the
    // reader is already through it — the sweep that awards it runs at night.
    $user = challenged(['visit_streak' => 9, 'longest_visit_streak' => 9]);
    earn($user, 'visits.1');

    $visits = collect(challengesFor($user)['medals'])->firstWhere('track', 'visits');

    expect($visits['progress'])->toBe(['now' => 9, 'goal' => 7, 'unlocking' => true]);
});

it('leaves a finished track without a medal to aim at', function (): void {
    $user = challenged(['visit_streak' => 400, 'longest_visit_streak' => 400]);
    earn($user, 'visits.1', 'visits.2', 'visits.3', 'visits.4', 'visits.5');

    $challenges = challengesFor($user);

    // The number keeps climbing with nothing left to reach: the pill fills its
    // ring rather than showing a goal that no longer exists.
    expect($challenges['visit_streak'])->toBe(400)
        ->and(collect($challenges['medals'])->firstWhere('track', 'visits'))->toBeNull()
        ->and(collect($challenges['medals'])->firstWhere('track', 'visit_weeks'))->not->toBeNull();
});

it('counts only what the month in progress left without a category', function (): void {
    $user = challenged();

    recordFor($user, 3, monthsAgo: 0);
    recordFor($user, 2, monthsAgo: 0, categorized: true);
    // Last month is somebody else's problem — the prompt is about the month
    // nobody has tidied yet.
    recordFor($user, 4, monthsAgo: 1);

    expect(challengesFor($user)['uncategorized']['count'])->toBe(3);
});

it('never counts another reader\'s pile', function (): void {
    $user = challenged();
    $stranger = challenged();

    recordFor($stranger, 5, monthsAgo: 0);

    expect(challengesFor($user)['uncategorized'])->toBeNull();
});

it('carries the categorized medal so the prompt can show what the work is worth', function (): void {
    $user = challenged();

    recordFor($user, 1, monthsAgo: 0);
    recordFor($user, 2, monthsAgo: 1, categorized: true);

    $medal = challengesFor($user)['uncategorized']['medal'];

    expect($medal['track'])->toBe('categorized')
        ->and($medal['figure'])->toBe(['type' => 'months', 'value' => 1, 'currency' => null])
        ->and($medal['progress'])->toBe(['now' => 1, 'goal' => 1, 'unlocking' => true]);
});

it('stops asking for three days once the reader says not now', function (): void {
    $user = challenged();
    recordFor($user, 2, monthsAgo: 0);

    expect(challengesFor($user)['uncategorized']['count'])->toBe(2);

    $this->actingAs($user)
        ->post(route('achievements.uncategorized.snooze'))
        ->assertOk();

    expect($user->fresh()->uncategorized_prompt_snoozed_until->toDateString())
        ->toBe(now()->addDays(3)->toDateString())
        ->and(challengesFor($user)['uncategorized'])->toBeNull();
});

it('asks again once the snooze has run out', function (): void {
    $user = challenged(['uncategorized_prompt_snoozed_until' => now()->subMinute()]);
    recordFor($user, 2, monthsAgo: 0);

    expect(challengesFor($user)['uncategorized']['count'])->toBe(2);
});
