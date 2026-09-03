<?php

use App\Models\User;
use App\Services\Achievements\Evaluator;
use App\Services\Achievements\History;
use App\Services\Achievements\HistoryBuilder;
use App\Services\Achievements\Unlock;

/*
 * The rules, read against a history handed straight in. What the history is
 * built from is another test's business; this one is about which medals a given
 * past contains and, just as much, the month each of them is dated to.
 */

/**
 * @param  array<string, array<string, int|float>>  $months
 * @param  array<string, int>  $extra
 * @return array<string, Unlock>
 */
function evaluate(array $months, array $extra = []): array
{
    $user = User::factory()->make(['currency_code' => 'EUR']);

    $history = new History(
        currency: 'EUR',
        months: $months,
        netWorth: $extra['netWorth'] ?? [],
        liquid: $extra['liquid'] ?? [],
        transactions: $extra['transactions'] ?? array_fill_keys(array_keys($months), 1),
        uncategorized: $extra['uncategorized'] ?? array_fill_keys(array_keys($months), 0),
        events: $extra['events'] ?? [],
        goalReachedAmount: $extra['goalReachedAmount'] ?? null,
    );

    test()->mock(HistoryBuilder::class, fn ($mock) => $mock->shouldReceive('for')->andReturn($history));

    return app(Evaluator::class)->for($user);
}

/**
 * @param  array<string, int|float>  $values
 * @return array<string, array<string, int|float>>
 */
function monthsOf(string $figure, array $values): array
{
    return collect($values)
        ->map(fn (int|float $value): array => [$figure => $value])
        ->all();
}

it('dates a milestone to the month it was really crossed, not to today', function (): void {
    $unlocks = evaluate(monthsOf('savings', [
        '2023-01' => 5000,
        '2023-02' => 120000,   // €1,200: clears the first rung and the second
        '2023-03' => 300000,
    ]));

    expect($unlocks['monthly_saving.1']->month)->toBe('2023-02')
        ->and($unlocks['monthly_saving.2']->month)->toBe('2023-02')
        ->and($unlocks['monthly_saving.3']->month)->toBe('2023-03')
        ->and($unlocks)->not->toHaveKey('monthly_saving.4');
});

it('freezes the figure that earned the medal, not the threshold', function (): void {
    $unlocks = evaluate(monthsOf('savings', ['2023-02' => 276000]));

    expect($unlocks['monthly_saving.2']->attributes()['value'])->toBe(276000)
        ->and($unlocks['monthly_saving.2']->attributes()['currency_code'])->toBe('EUR');
});

it('counts a yearly ladder per calendar year rather than forever', function (): void {
    $unlocks = evaluate(monthsOf('savings', [
        '2023-11' => 80000,
        '2023-12' => 80000,   // €1,600 in 2023: over the first rung
        '2024-01' => 80000,   // the running total restarts here
    ]));

    expect($unlocks['yearly_saving.1']->month)->toBe('2023-12');
});

it('counts a streak only from the month achievements arrived', function (): void {
    config()->set('achievements.streaks_from', '2026-09');

    $unlocks = evaluate(monthsOf('net', [
        '2026-06' => 100, '2026-07' => 100, '2026-08' => 100,
        '2026-09' => 100, '2026-10' => 100, '2026-11' => 100,
    ]));

    // Six months in the black, but only three of them count.
    expect($unlocks['streaks.1']->month)->toBe('2026-11')
        ->and($unlocks['streaks.1']->attributes()['value'])->toBe(3);
});

it('breaks a streak on a month in the red', function (): void {
    config()->set('achievements.streaks_from', '2026-01');

    $unlocks = evaluate(monthsOf('net', [
        '2026-01' => 100, '2026-02' => 100, '2026-03' => -100,
        '2026-04' => 100, '2026-05' => 100,
    ]));

    expect($unlocks)->not->toHaveKey('streaks.1');
});

it('counts months fully categorized in a row, and starts again when one is not', function (): void {
    $months = monthsOf('net', ['2024-01' => 1, '2024-02' => 1, '2024-03' => 1, '2024-04' => 1]);

    $unlocks = evaluate($months, [
        'transactions' => ['2024-01' => 10, '2024-02' => 10, '2024-03' => 10, '2024-04' => 10],
        'uncategorized' => ['2024-01' => 0, '2024-02' => 3, '2024-03' => 0, '2024-04' => 0],
    ]);

    expect($unlocks['categorized.1']->month)->toBe('2024-01')
        ->and($unlocks)->not->toHaveKey('categorized.2');
});

it('adds transactions up across months and dates the count where it landed', function (): void {
    $unlocks = evaluate(monthsOf('net', ['2024-01' => 1, '2024-02' => 1, '2024-03' => 1]), [
        'transactions' => ['2024-01' => 20, '2024-02' => 25, '2024-03' => 10],
    ]);

    // 20, then 45, then 55: the fiftieth transaction landed in March.
    expect($unlocks['transactions.1']->month)->toBe('2024-01')
        ->and($unlocks['transactions.2']->month)->toBe('2024-03')
        ->and($unlocks['transactions.2']->attributes()['value'])->toBe(55)
        ->and($unlocks)->not->toHaveKey('transactions.3');
});

it('reads an emergency fund against the six months of spending before it', function (): void {
    $months = collect(range(1, 8))
        ->mapWithKeys(fn (int $i): array => [sprintf('2024-%02d', $i) => ['expense' => 100000]])
        ->all();

    $unlocks = evaluate($months, [
        'liquid' => array_merge(
            array_fill_keys(array_keys($months), 0),
            ['2024-07' => 350000],  // three and a half months of cover
        ),
    ]);

    expect($unlocks['safety.2']->month)->toBe('2024-07')
        ->and($unlocks['safety.2']->attributes()['value'])->toBe(350000)
        ->and($unlocks)->not->toHaveKey('safety.3');
});

it('will not award a medal on half a window of history', function (): void {
    // Two months only: there is no six-month average to beat or to cover.
    $unlocks = evaluate([
        '2024-01' => ['expense' => 100000, 'savings' => 1],
        '2024-02' => ['expense' => 100000, 'savings' => 900000],
    ], ['liquid' => ['2024-01' => 9000000, '2024-02' => 9000000]]);

    expect($unlocks)->not->toHaveKey('safety.2')
        ->and($unlocks)->not->toHaveKey('momentum.1');
});

it('reads a year of net worth growth as a percentage', function (): void {
    $netWorth = collect(range(0, 12))
        ->mapWithKeys(fn (int $i): array => [now()->subMonths(12 - $i)->format('Y-m') => $i === 12 ? 1150000 : 1000000])
        ->all();

    $unlocks = evaluate(monthsOf('net', array_fill_keys(array_keys($netWorth), 1)), ['netWorth' => $netWorth]);

    expect($unlocks['momentum.2']->attributes()['percent'])->toBe(15.0);
});

it('finds nothing in a history with no months', function (): void {
    expect(evaluate([]))->toBe([]);
});
