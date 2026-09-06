<?php

use App\Enums\CategoryType;
use App\Features\Achievements;
use App\Models\Account;
use App\Models\Achievement;
use App\Models\Category;
use App\Models\MonthlySummary;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;

/*
 * The progress screen.
 *
 * Every medal is sent, earned or not, because the empty slots are the road
 * ahead. What a locked one is called is still not sent: a silhouette that leaks
 * its own name through the network tab is not a silhouette.
 *
 * The next rung of each track is the deliberate exception. Thirteen identical
 * silhouettes say nothing about what there is to chase, so exactly one medal
 * per ladder — the first nobody has earned — arrives named, with the figure to
 * reach and, where the current figure is cheap to read, how far along the reader
 * already is. Everything past it stays three question marks.
 */

beforeEach(function (): void {
    Cache::flush();
    config()->set('achievements.enabled', true);
    // These assertions are about the props the screen is handed, never about
    // the HTML a server-side render would produce. Left on, Inertia posts every
    // page to its SSR gateway — the running dev server, in development — and
    // the suite's stray-request guard fails the test for a reason that has
    // nothing to do with the screen.
    config()->set('inertia.ssr.enabled', false);
});

/**
 * The props the screen was rendered with. Asked for as an Inertia visit rather
 * than a plain one, so the assertion does not go looking for a server-rendered
 * page and the SSR bundle with it.
 *
 * @return array<string, mixed>
 */
function progressProps(User $user): array
{
    $props = [];

    test()->actingAs($user)
        ->get(route('achievements.index'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    return $props;
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function medalsIn(array $props)
{
    return collect($props['tracks'])->flatMap(fn (array $track): array => $track['medals']);
}

function readerWithMedals(int $count = 2): User
{
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);

    collect(['transactions.1', 'net_worth.1', 'net_worth.2'])
        ->take($count)
        ->each(fn (string $key) => Achievement::factory()->key($key)->create([
            'user_id' => $user->id,
            'space_id' => $user->activeSpace()->id,
        ]));

    return $user;
}

it('sends every medal in the catalog, earned or not', function (): void {
    $user = readerWithMedals();

    $this->actingAs($user)
        ->get(route('achievements.index'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $page->component('achievements/index')
                ->where('overview.unlocked', 2)
                ->where('overview.total', 59)
                ->has('tracks', 13);

            expect(medalsIn($page->toArray()['props']))->toHaveCount(59);
        });
});

it('keeps the name of a medal past the next one to itself', function (): void {
    $user = readerWithMedals(1);

    $locked = medalsIn(progressProps($user))->where('state', 'locked');

    expect($locked)->not->toBeEmpty();

    $locked->each(function (array $medal): void {
        expect($medal['name'])->toBeNull()
            ->and($medal['icon'])->toBeNull()
            ->and($medal['figure'])->toBeNull()
            ->and($medal['achieved_on'])->toBeNull()
            // The tier still travels: the shape of what is left has to be
            // readable, only its names do not.
            ->and($medal['rarity'])->toBeString();
    });
});

it('sends a figure as a number for the client to write, so privacy mode can hide it', function (): void {
    $user = readerWithMedals();

    $medal = medalsIn(progressProps($user))->firstWhere('key', 'net_worth.1');

    expect($medal['figure'])->toBe(['type' => 'money', 'value' => 1000000, 'currency' => 'EUR']);
});

it('scales the ladder to the reader\'s own currency', function (): void {
    $user = readerWithMedals();
    $user->forceFill(['currency_code' => 'COP'])->save();

    $medal = medalsIn(progressProps($user))->firstWhere('key', 'net_worth.1');

    // 20 million pesos, with no centavos to scale by.
    expect($medal['figure'])->toBe(['type' => 'money', 'value' => 20000000, 'currency' => 'COP']);
});

it('says nothing about how rare a medal is until enough members have been evaluated', function (): void {
    $user = readerWithMedals();

    $shares = medalsIn(progressProps($user))->pluck('share')->unique();

    expect($shares->all())->toBe([null]);
});

it('shows the real share once the floor is cleared', function (): void {
    config()->set('achievements.rarity_floor', 2);
    $user = readerWithMedals();

    // A second member, holding one of the same medals.
    $other = User::factory()->onboarded()->create();
    Achievement::factory()->key('transactions.1')->create([
        'user_id' => $other->id,
        'space_id' => $other->activeSpace()->id,
    ]);

    $medals = medalsIn(progressProps($user));

    // Whole numbers arrive from JSON as integers, so compare loosely.
    expect($medals->firstWhere('key', 'transactions.1')['share'])->toEqual(100)
        ->and($medals->firstWhere('key', 'net_worth.1')['share'])->toEqual(50);
});

it('is not there while the feature is off', function (): void {
    config()->set('achievements.enabled', false);
    Feature::purge(Achievements::class);

    $this->actingAs(readerWithMedals())
        ->get(route('achievements.index'))
        ->assertNotFound();
});

it('greets a reader with nothing yet without pretending they have something', function (): void {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->get(route('achievements.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('overview.unlocked', 0)
            ->where('overview.latest', null)
            ->where('overview.streak', null));
});

it('tells the account menu how far through the medals a reader is', function (): void {
    $user = readerWithMedals();
    // The badge reads the counter the sweep keeps, not a count on every render.
    $user->forceFill(['achievements_count' => $user->achievements()->count()])->saveQuietly();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('achievements.unlocked', 2)
            ->where('achievements.total', 59));
});

it('says nothing about medals to a reader the feature is off for', function (): void {
    config()->set('achievements.enabled', false);
    Feature::purge(Achievements::class);

    $this->actingAs(readerWithMedals())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('achievements', null));
});

/**
 * Transactions in one month, with or without a category on them. Month zero is
 * the one in progress.
 *
 * Reuses one account and one category per reader, so a run of months is a run
 * of rows rather than a tree of factories.
 */
function recordIn(User $user, int $monthsAgo, bool $categorized = true, int $count = 1): void
{
    $space = $user->activeSpace();

    $account = $user->accounts()->first() ?? Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
        'currency_code' => 'EUR',
    ]);

    $category = Category::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'Groceries'],
        ['space_id' => $space->id, 'type' => CategoryType::Expense],
    );

    Transaction::factory()->count($count)->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
        'account_id' => $account->id,
        'category_id' => $categorized ? $category->id : null,
        // The first of the month, then four days in: subtracting whole months
        // from a 31st slides into the wrong one.
        'transaction_date' => now()->startOfMonth()->subMonths($monthsAgo)->addDays(4),
        'currency_code' => 'EUR',
    ]);
}

it('reveals the next medal of a track, and nothing past it', function (): void {
    $user = readerWithMedals();

    $medals = medalsIn(progressProps($user))->keyBy('key');

    expect($medals['net_worth.2']['state'])->toBe('next')
        ->and($medals['net_worth.2']['name'])->toBe('Net worth')
        ->and($medals['net_worth.2']['icon'])->toBe('landmark')
        ->and($medals['net_worth.2']['figure'])->toBe(['type' => 'money', 'value' => 2500000, 'currency' => 'EUR'])
        // Nothing has happened for it yet: no figure reached, no date.
        ->and($medals['net_worth.2']['reached'])->toBeNull()
        ->and($medals['net_worth.2']['achieved_on'])->toBeNull()
        // The rung after it is still a silhouette.
        ->and($medals['net_worth.3']['state'])->toBe('locked')
        ->and($medals['net_worth.3']['name'])->toBeNull()
        ->and($medals['net_worth.3']['figure'])->toBeNull();
});

it('opens with the first rung of all thirteen tracks for a reader who has nothing', function (): void {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);

    $next = medalsIn(progressProps($user))->where('state', 'next');

    expect($next->pluck('key')->all())->toBe([
        'visits.1', 'visit_weeks.1', 'transactions.1', 'categorized.1', 'hygiene.1',
        'monthly_saving.1', 'monthly_investing.1', 'yearly_saving.1', 'net_worth.1',
        'safety.1', 'streaks.1', 'savings_rate.1', 'momentum.1',
    ]);

    $next->each(fn (array $medal) => expect($medal['name'])->toBeString());
});

it('has nothing left to reveal on a track that is finished', function (): void {
    $user = readerWithMedals(0);

    collect(['visit_weeks.1', 'visit_weeks.2', 'visit_weeks.3', 'visit_weeks.4'])
        ->each(fn (string $key) => Achievement::factory()->key($key)->create([
            'user_id' => $user->id,
            'space_id' => $user->activeSpace()->id,
        ]));

    $track = collect(progressProps($user)['tracks'])->firstWhere('key', 'visit_weeks');

    expect(collect($track['medals'])->pluck('state')->unique()->all())->toBe(['earned']);
});

it('puts a figure under the next medal only where reading it is cheap', function (): void {
    $user = readerWithMedals();

    $next = medalsIn(progressProps($user))->where('state', 'next');

    // The five tracks a column, a count or the last report can answer. The
    // eight money ones would need the whole history built on every render.
    expect($next->filter(fn (array $medal): bool => $medal['progress'] !== null)->pluck('key')->all())
        ->toBe(['visits.1', 'visit_weeks.1', 'transactions.2', 'categorized.1', 'streaks.1']);
});

it('leaves the first transaction without a bar, because it is an event and not a count', function (): void {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);

    $medal = medalsIn(progressProps($user))->firstWhere('key', 'transactions.1');

    expect($medal['state'])->toBe('next')
        ->and($medal['figure'])->toBeNull()
        ->and($medal['progress'])->toBeNull();
});

it('measures a visit streak by the longest run, which is what the medal is awarded on', function (): void {
    $user = readerWithMedals(0);
    $user->forceFill(['visit_streak' => 1, 'longest_visit_streak' => 2])->saveQuietly();

    $medal = medalsIn(progressProps($user))->firstWhere('key', 'visits.1');

    expect($medal['progress'])->toBe(['now' => 2, 'goal' => 3, 'unlocking' => false]);
});

it('says a medal unlocks tonight once the reader is already past it', function (): void {
    $user = readerWithMedals(0);
    $user->forceFill(['longest_visit_streak' => 35])->saveQuietly();

    collect(['visits.1', 'visits.2'])->each(fn (string $key) => Achievement::factory()->key($key)->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]));

    // Thirty days crossed today, with the sweep that awards it still hours off.
    $medal = medalsIn(progressProps($user))->firstWhere('key', 'visits.3');

    expect($medal['progress'])->toBe(['now' => 35, 'goal' => 30, 'unlocking' => true]);
});

it('counts the transactions a reader recorded in closed months', function (): void {
    $user = readerWithMedals();
    recordIn($user, 1);
    recordIn($user, 2);

    $medal = medalsIn(progressProps($user))->firstWhere('key', 'transactions.2');

    expect($medal['progress'])->toBe(['now' => 2, 'goal' => 50, 'unlocking' => false]);
});

it('leaves the bar at nothing while a reader has only recorded in the month in progress', function (): void {
    $user = readerWithMedals();
    recordIn($user, 0, count: 3);

    $medal = medalsIn(progressProps($user))->firstWhere('key', 'transactions.2');

    // The sweep reads up to the last closed month, so nothing recorded this
    // month is anywhere near the medal yet. The bar says so.
    expect($medal['progress'])->toBe(['now' => 0, 'goal' => 50, 'unlocking' => false]);
});

it('waits for the closed months to cross the threshold before promising the medal tonight', function (): void {
    $user = readerWithMedals();
    recordIn($user, 1, count: 49);
    // Enough this month to carry a live count well past fifty, which is
    // exactly the promise that would be a lie until the month closes.
    recordIn($user, 0, count: 10);

    $medal = medalsIn(progressProps($user))->firstWhere('key', 'transactions.2');

    expect($medal['progress'])->toBe(['now' => 49, 'goal' => 50, 'unlocking' => false]);

    recordIn($user, 1);

    $medal = medalsIn(progressProps($user))->firstWhere('key', 'transactions.2');

    expect($medal['progress'])->toBe(['now' => 50, 'goal' => 50, 'unlocking' => true]);
});

it('counts the closed months in a row that were left fully categorized', function (): void {
    $user = readerWithMedals(0);
    Achievement::factory()->key('categorized.1')->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    // Two closed months in a row, and one before them with something left
    // uncategorized: the run the next medal needs is the one still going, and a
    // broken one has to be rebuilt from scratch, so the earlier months do not
    // count towards it.
    recordIn($user, 3, categorized: false);
    collect([2, 1])->each(fn (int $ago) => recordIn($user, $ago));

    $medal = medalsIn(progressProps($user))->firstWhere('key', 'categorized.2');

    expect($medal['state'])->toBe('next')
        ->and($medal['progress'])->toBe(['now' => 2, 'goal' => 3, 'unlocking' => false]);
});

it('reads the saving streak off the last report, like the overview does', function (): void {
    $user = readerWithMedals(0);
    MonthlySummary::factory()->sent()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    $medal = medalsIn(progressProps($user))->firstWhere('key', 'streaks.1');

    // The factory's payload reports a five-month streak, so the first rung is
    // already behind the reader and waiting on tonight's sweep.
    expect($medal['progress'])->toBe(['now' => 5, 'goal' => 3, 'unlocking' => true]);
});

it('reveals one medal on a track whose rungs are events rather than a ladder', function (): void {
    $user = readerWithMedals(0);

    // Data hygiene is not really a ladder: connecting a bank, writing a rule
    // and setting a budget happen in whatever order a reader does them, so a
    // later rung can be earned while an earlier one is not. The track still
    // reveals exactly one, and every rung keeps the state it has earned —
    // which is how the screen already drew this before anything was revealed.
    collect(['hygiene.1', 'hygiene.4', 'hygiene.5'])
        ->each(fn (string $key) => Achievement::factory()->key($key)->create([
            'user_id' => $user->id,
            'space_id' => $user->activeSpace()->id,
        ]));

    $track = collect(progressProps($user)['tracks'])->firstWhere('key', 'hygiene');

    expect(collect($track['medals'])->pluck('state')->all())
        ->toBe(['earned', 'next', 'locked', 'earned', 'earned', 'locked'])
        ->and($track['unlocked'])->toBe(3);
});
