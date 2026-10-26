<?php

use App\Models\Account;
use App\Models\User;
use App\Services\LoanBalanceGeneratorService;
use App\Services\RealEstateBalanceGeneratorService;
use Carbon\Carbon;

/*
 * The loan and the real estate generators walk the same timeline: the opening
 * amount on the start (or purchase) date, one point on the 1st of every month,
 * and the current amount today. Each row below is one generator with the
 * amounts its own test used.
 */

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
});

it('generates a long series across multiple upsert batches without gaps or duplicates', function (string $service, string $accountState, int $opening, int $current) {
    $this->travelTo(Carbon::parse('2026-06-15'));

    $account = Account::factory()->{$accountState}()->create([
        'user_id' => $this->user->id,
    ]);

    // ~46 years of monthly points is well over the internal upsert batch size,
    // so this drives the chunked build/upsert path that stops the queue worker
    // from exhausting memory on an ancient start date (PHP-LARAVEL-49).
    app($service)->generateHistoricalBalances($account, $opening, Carbon::parse('1980-01-15'), $current);

    $balances = $account->balances()->orderBy('balance_date')->get();
    $dates = $balances->pluck('balance_date')->map->toDateString();

    // Enough points to span more than one batch...
    expect($balances->count())->toBeGreaterThan(500);
    // ...and the batch boundaries must not drop or duplicate any date.
    expect($dates->unique()->count())->toBe($balances->count());
    // Endpoints stay anchored across the batched writes.
    expect($dates->first())->toBe('1980-01-15');
    expect($balances->first()->balance)->toBe($opening);
    expect($dates->last())->toBe('2026-06-15');
    expect($balances->last()->balance)->toBe($current);
})->with([
    'loan' => [LoanBalanceGeneratorService::class, 'loan', 15000000, 3000000],
    'real estate' => [RealEstateBalanceGeneratorService::class, 'realEstate', 10000000, 16000000],
]);

it('generates linearly interpolated balances from start date to today', function (string $service, string $accountState, int $opening, int $current, bool $paidDown) {
    $this->travelTo(Carbon::parse('2026-03-15'));

    $account = Account::factory()->{$accountState}()->create([
        'user_id' => $this->user->id,
    ]);

    app($service)->generateHistoricalBalances($account, $opening, Carbon::parse('2025-11-15'), $current);

    $balances = $account->balances()->orderBy('balance_date')->get();

    // start date + Dec 1 + Jan 1 + Feb 1 + Mar 1 + today (Mar 15) = 6
    expect($balances)->toHaveCount(6);

    expect($balances->first()->balance_date->toDateString())->toBe('2025-11-15');
    expect($balances->first()->balance)->toBe($opening);

    expect($balances->last()->balance_date->toDateString())->toBe('2026-03-15');
    expect($balances->last()->balance)->toBe($current);

    // Values move steadily from the opening amount towards the current one.
    for ($i = 1; $i < $balances->count(); $i++) {
        $paidDown
            ? expect($balances[$i]->balance)->toBeLessThanOrEqual($balances[$i - 1]->balance)
            : expect($balances[$i]->balance)->toBeGreaterThanOrEqual($balances[$i - 1]->balance);
    }
})->with([
    'loan being paid down' => [LoanBalanceGeneratorService::class, 'loan', 15000000, 12000000, true],
    'real estate gaining value' => [RealEstateBalanceGeneratorService::class, 'realEstate', 20000000, 24000000, false],
]);

it('creates a single balance when start date is today', function (string $service, string $accountState, int $amount) {
    $account = Account::factory()->{$accountState}()->create([
        'user_id' => $this->user->id,
    ]);

    app($service)->generateHistoricalBalances($account, $amount, Carbon::today(), $amount);

    $balances = $account->balances;

    expect($balances)->toHaveCount(1);
    expect($balances->first()->balance)->toBe($amount);
    expect($balances->first()->balance_date->toDateString())->toBe(now()->toDateString());
})->with([
    'loan' => [LoanBalanceGeneratorService::class, 'loan', 20000000],
    'real estate' => [RealEstateBalanceGeneratorService::class, 'realEstate', 30000000],
]);

it('does not create balances when start date is in the future', function (string $service, string $accountState, int $amount) {
    $account = Account::factory()->{$accountState}()->create([
        'user_id' => $this->user->id,
    ]);

    app($service)->generateHistoricalBalances($account, $amount, Carbon::today()->addMonth(), $amount);

    expect($account->balances)->toHaveCount(0);
})->with([
    'loan' => [LoanBalanceGeneratorService::class, 'loan', 15000000],
    'real estate' => [RealEstateBalanceGeneratorService::class, 'realEstate', 20000000],
]);

it('updates an existing balance date instead of duplicating it', function (string $service, string $accountState, int $opening, int $current) {
    $this->travelTo(Carbon::parse('2026-03-15'));

    $account = Account::factory()->{$accountState}()->create([
        'user_id' => $this->user->id,
    ]);

    $account->balances()->create([
        'balance_date' => '2026-03-15',
        'balance' => 99999999,
    ]);

    app($service)->generateHistoricalBalances($account, $opening, Carbon::parse('2026-02-01'), $current);

    $todayBalances = $account->balances()->where('balance_date', '2026-03-15')->get();
    expect($todayBalances)->toHaveCount(1);
    expect($todayBalances->first()->balance)->toBe($current);
})->with([
    'loan' => [LoanBalanceGeneratorService::class, 'loan', 15000000, 12000000],
    'real estate' => [RealEstateBalanceGeneratorService::class, 'realEstate', 20000000, 24000000],
]);

it('places intermediate balances on the 1st of each month', function (string $service, string $accountState, int $opening, int $current) {
    $this->travelTo(Carbon::parse('2026-04-20'));

    $account = Account::factory()->{$accountState}()->create([
        'user_id' => $this->user->id,
    ]);

    app($service)->generateHistoricalBalances($account, $opening, Carbon::parse('2026-01-15'), $current);

    $dates = $account->balances()->orderBy('balance_date')->pluck('balance_date')
        ->map->toDateString()->toArray();

    expect($dates)->toBe([
        '2026-01-15',
        '2026-02-01',
        '2026-03-01',
        '2026-04-01',
        '2026-04-20',
    ]);
})->with([
    'loan' => [LoanBalanceGeneratorService::class, 'loan', 15000000, 13000000],
    'real estate' => [RealEstateBalanceGeneratorService::class, 'realEstate', 10000000, 12000000],
]);

it('generates only balances within a from/to range excluding today', function (string $service, string $accountState, int $opening, int $current) {
    $this->travelTo(Carbon::parse('2026-06-15'));

    $account = Account::factory()->{$accountState}()->create([
        'user_id' => $this->user->id,
    ]);

    // Generate only the older portion: start date to Feb 28
    app($service)->generateHistoricalBalances(
        $account,
        $opening,
        Carbon::parse('2026-01-15'),
        $current,
        from: Carbon::parse('2026-01-15'),
        to: Carbon::parse('2026-02-28'),
    );

    $balances = $account->balances()->orderBy('balance_date')->get();
    $dates = $balances->pluck('balance_date')->map->toDateString()->toArray();

    expect($dates)->toBe([
        '2026-01-15', // start date
        '2026-02-01', // 1st of month
    ]);

    // The start date carries the opening amount
    expect($balances[0]->balance)->toBe($opening);
})->with([
    'loan' => [LoanBalanceGeneratorService::class, 'loan', 15000000, 9000000],
    'real estate' => [RealEstateBalanceGeneratorService::class, 'realEstate', 10000000, 16000000],
]);
