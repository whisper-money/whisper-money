<?php

use App\Features\Achievements;
use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;

/*
 * The progress screen.
 *
 * Every medal is sent, earned or not, because the empty slots are the road
 * ahead. What a locked one is called is not sent: a silhouette that leaks its
 * own name through the network tab is not a silhouette.
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
                ->where('overview.total', 50)
                ->has('tracks', 11);

            expect(medalsIn($page->toArray()['props']))->toHaveCount(50);
        });
});

it('keeps the name of a medal still to come to itself', function (): void {
    $user = readerWithMedals(1);

    $locked = medalsIn(progressProps($user))->filter(fn (array $medal): bool => $medal['locked']);

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
            ->where('achievements.total', 50));
});

it('says nothing about medals to a reader the feature is off for', function (): void {
    config()->set('achievements.enabled', false);
    Feature::purge(Achievements::class);

    $this->actingAs(readerWithMedals())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('achievements', null));
});
