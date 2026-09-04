<?php

use App\Services\Achievements\Catalog;
use App\Services\Achievements\Definition;
use App\Services\Achievements\Ladders;

/*
 * The catalog is data, and everything downstream trusts its shape: the screen
 * groups by track, the ladders index by tier, and a key is what a recorded medal
 * is addressed by forever.
 */

it('holds fifty medals, each with a key of its own', function (): void {
    $all = app(Catalog::class)->all();

    expect($all)->toHaveCount(50)
        ->and($all->keys()->unique())->toHaveCount(50);
});

it('reads a track\'s rungs off the catalog, so adding one is a single edit', function (): void {
    $catalog = app(Catalog::class);

    expect($catalog->tiers('transactions'))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($catalog->tiers('categorized'))->toBe([1, 2, 3, 4, 5])
        ->and($catalog->tiers('net_worth'))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($catalog->tiers('nothing-of-the-sort'))->toBe([]);
});

it('gives every medal a track the screen knows how to draw', function (): void {
    $tracks = array_keys(app(Catalog::class)->tracks());

    app(Catalog::class)->all()->each(function (Definition $definition) use ($tracks): void {
        expect($tracks)->toContain($definition->track);
    });
});

it('numbers the medals of a track from one, in order', function (): void {
    app(Catalog::class)->all()
        ->groupBy(fn (Definition $definition): string => $definition->track)
        ->each(function ($medals, string $track): void {
            $tiers = $medals->pluck('tier')->all();

            expect($tiers)->toBe(range(1, count($tiers)), "track {$track}");
        });
});

it('has a rung for every money medal in every currency it ships', function (): void {
    $ladders = app(Ladders::class);
    $currencies = array_keys(config('achievements.ladders'));

    app(Catalog::class)->all()
        ->filter(fn (Definition $definition): bool => $definition->ladder() !== null)
        ->each(function (Definition $definition) use ($ladders, $currencies): void {
            foreach ($currencies as $currency) {
                expect($ladders->rung($definition->ladder(), $definition->tier, $currency))
                    ->toBeInt("{$definition->key} in {$currency}");
            }
        });
});

it('scales a ladder to the currency the money is stored in', function (): void {
    $ladders = app(Ladders::class);

    // EUR keeps cents, so €100 is 10_000. COP has none in practice, so 200.000
    // pesos is 200_000 and not 20_000_000.
    expect($ladders->rung('monthly', 1, 'EUR'))->toBe(10000)
        ->and($ladders->rung('monthly', 1, 'COP'))->toBe(200000)
        ->and($ladders->rung('monthly', 1, 'CLP'))->toBe(50000);
});

it('measures a currency it has no ladder for in the fallback one', function (): void {
    $ladders = app(Ladders::class);

    expect($ladders->currencyFor('JPY'))->toBe('USD')
        ->and($ladders->currencyFor('eur'))->toBe('EUR')
        ->and($ladders->rung('net_worth', 1, 'JPY'))->toBe($ladders->rung('net_worth', 1, 'USD'));
});

it('keeps the ladders ascending, so a lower rung is never harder than a higher one', function (): void {
    $ladders = app(Ladders::class);

    foreach (array_keys(config('achievements.ladders')) as $currency) {
        foreach (['monthly', 'yearly', 'net_worth'] as $ladder) {
            $rungs = $ladders->rungs($ladder, $currency);

            expect($rungs)->toBe(array_values(collect($rungs)->sort()->unique()->all()), "{$ladder} in {$currency}");
        }
    }
});
