<?php

use App\Models\Account;
use App\Models\User;
use App\Services\RealEstateBalanceGeneratorService;
use Carbon\Carbon;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->service = app(RealEstateBalanceGeneratorService::class);
});

it('generates a long series across multiple upsert batches without gaps or duplicates', function () {
    $this->travelTo(Carbon::parse('2026-06-15'));

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    // ~46 years of monthly points is well over the internal upsert batch size,
    // so this drives the chunked build/upsert path that stops the queue worker
    // from exhausting memory on an ancient purchase date (PHP-LARAVEL-49).
    $this->service->generateHistoricalBalances(
        $account,
        purchasePrice: 10000000,
        purchaseDate: Carbon::parse('1980-01-15'),
        currentValue: 16000000,
    );

    $balances = $account->balances()->orderBy('balance_date')->get();
    $dates = $balances->pluck('balance_date')->map->toDateString();

    // Enough points to span more than one batch...
    expect($balances->count())->toBeGreaterThan(500);
    // ...and the batch boundaries must not drop or duplicate any date.
    expect($dates->unique()->count())->toBe($balances->count());
    // Endpoints stay anchored across the batched writes.
    expect($dates->first())->toBe('1980-01-15');
    expect($balances->first()->balance)->toBe(10000000);
    expect($dates->last())->toBe('2026-06-15');
    expect($balances->last()->balance)->toBe(16000000);
});

it('generates linearly interpolated balances from purchase date to today', function () {
    $this->travelTo(Carbon::parse('2026-03-15'));

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    $this->service->generateHistoricalBalances(
        $account,
        purchasePrice: 20000000, // 200,000.00
        purchaseDate: Carbon::parse('2025-11-15'),
        currentValue: 24000000, // 240,000.00
    );

    $balances = $account->balances()->orderBy('balance_date')->get();

    // purchase date + Dec 1 + Jan 1 + Feb 1 + Mar 1 + today (Mar 15) = 6
    expect($balances)->toHaveCount(6);

    // First = purchase price
    expect($balances->first()->balance_date->toDateString())->toBe('2025-11-15');
    expect($balances->first()->balance)->toBe(20000000);

    // Last = current value
    expect($balances->last()->balance_date->toDateString())->toBe('2026-03-15');
    expect($balances->last()->balance)->toBe(24000000);

    // Values should strictly increase (since current > purchase)
    for ($i = 1; $i < $balances->count(); $i++) {
        expect($balances[$i]->balance)->toBeGreaterThanOrEqual($balances[$i - 1]->balance);
    }
});

it('creates a single balance when purchase date is today', function () {
    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    $this->service->generateHistoricalBalances(
        $account,
        purchasePrice: 30000000,
        purchaseDate: Carbon::today(),
        currentValue: 30000000,
    );

    $balances = $account->balances;

    expect($balances)->toHaveCount(1);
    expect($balances->first()->balance)->toBe(30000000);
    expect($balances->first()->balance_date->toDateString())->toBe(now()->toDateString());
});

it('generates flat balances when purchase price equals current value', function () {
    $this->travelTo(Carbon::parse('2026-06-01'));

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    $this->service->generateHistoricalBalances(
        $account,
        purchasePrice: 50000000,
        purchaseDate: Carbon::parse('2026-01-01'),
        currentValue: 50000000,
    );

    $balances = $account->balances;

    foreach ($balances as $balance) {
        expect($balance->balance)->toBe(50000000);
    }
});

it('does not create balances when purchase date is in the future', function () {
    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    $this->service->generateHistoricalBalances(
        $account,
        purchasePrice: 20000000,
        purchaseDate: Carbon::today()->addMonth(),
        currentValue: 20000000,
    );

    expect($account->balances)->toHaveCount(0);
});

it('uses updateOrCreate to avoid duplicate balance dates', function () {
    $this->travelTo(Carbon::parse('2026-03-15'));

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    // Create an existing balance for today
    $account->balances()->create([
        'balance_date' => '2026-03-15',
        'balance' => 99999999,
    ]);

    $this->service->generateHistoricalBalances(
        $account,
        purchasePrice: 20000000,
        purchaseDate: Carbon::parse('2026-02-01'),
        currentValue: 24000000,
    );

    // Today's balance should be updated, not duplicated
    $todayBalances = $account->balances()->where('balance_date', '2026-03-15')->get();
    expect($todayBalances)->toHaveCount(1);
    expect($todayBalances->first()->balance)->toBe(24000000);
});

it('handles depreciation (current value less than purchase price)', function () {
    $this->travelTo(Carbon::parse('2026-06-01'));

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    $this->service->generateHistoricalBalances(
        $account,
        purchasePrice: 30000000,
        purchaseDate: Carbon::parse('2026-01-01'),
        currentValue: 27000000,
    );

    $balances = $account->balances()->orderBy('balance_date')->get();

    // First = purchase price, last = current (lower)
    expect($balances->first()->balance)->toBe(30000000);
    expect($balances->last()->balance)->toBe(27000000);

    // Values should decrease
    for ($i = 1; $i < $balances->count(); $i++) {
        expect($balances[$i]->balance)->toBeLessThanOrEqual($balances[$i - 1]->balance);
    }
});

it('places intermediate balances on the 1st of each month', function () {
    $this->travelTo(Carbon::parse('2026-04-20'));

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    $this->service->generateHistoricalBalances(
        $account,
        purchasePrice: 10000000,
        purchaseDate: Carbon::parse('2026-01-15'),
        currentValue: 12000000,
    );

    $balances = $account->balances()->orderBy('balance_date')->get();
    $dates = $balances->pluck('balance_date')->map->toDateString()->toArray();

    expect($dates)->toBe([
        '2026-01-15', // purchase date
        '2026-02-01', // 1st of month
        '2026-03-01', // 1st of month
        '2026-04-01', // 1st of month
        '2026-04-20', // today
    ]);
});

it('generates only balances within a from/to date range', function () {
    $this->travelTo(Carbon::parse('2026-06-15'));

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    // Purchase was Jan 15, today is Jun 15 — but only generate Mar 1 to Jun 15
    $this->service->generateHistoricalBalances(
        $account,
        purchasePrice: 10000000,
        purchaseDate: Carbon::parse('2026-01-15'),
        currentValue: 16000000,
        from: Carbon::parse('2026-03-01'),
    );

    $balances = $account->balances()->orderBy('balance_date')->get();
    $dates = $balances->pluck('balance_date')->map->toDateString()->toArray();

    // Should only include dates from Mar 1 onward (no purchase date, no Feb 1)
    expect($dates)->toBe([
        '2026-03-01',
        '2026-04-01',
        '2026-05-01',
        '2026-06-01',
        '2026-06-15', // today
    ]);

    // Interpolation should still be based on the full timeline (Jan 15 to Jun 15 = 151 days)
    $totalDays = 151;

    // Mar 1: 45 days elapsed from Jan 15
    expect($balances[0]->balance)->toBe((int) round(10000000 + 6000000 * (45 / $totalDays)));

    // Today (Jun 15) should be the current value
    expect($balances->last()->balance)->toBe(16000000);
});

it('generates only balances within a from/to range excluding today', function () {
    $this->travelTo(Carbon::parse('2026-06-15'));

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    // Generate only the older portion: purchase date to Feb 28
    $this->service->generateHistoricalBalances(
        $account,
        purchasePrice: 10000000,
        purchaseDate: Carbon::parse('2026-01-15'),
        currentValue: 16000000,
        from: Carbon::parse('2026-01-15'),
        to: Carbon::parse('2026-02-28'),
    );

    $balances = $account->balances()->orderBy('balance_date')->get();
    $dates = $balances->pluck('balance_date')->map->toDateString()->toArray();

    expect($dates)->toBe([
        '2026-01-15', // purchase date
        '2026-02-01', // 1st of month
    ]);

    // Purchase date should be purchase price
    expect($balances[0]->balance)->toBe(10000000);
});

it('combines two ranged calls to produce the same result as a full generation', function () {
    $this->travelTo(Carbon::parse('2026-06-15'));

    // Generate full range on one account
    $fullAccount = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    $this->service->generateHistoricalBalances(
        $fullAccount,
        purchasePrice: 10000000,
        purchaseDate: Carbon::parse('2026-01-15'),
        currentValue: 16000000,
    );

    // Generate split ranges on another account
    $splitAccount = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    $splitPoint = Carbon::parse('2026-03-01');

    $this->service->generateHistoricalBalances(
        $splitAccount,
        purchasePrice: 10000000,
        purchaseDate: Carbon::parse('2026-01-15'),
        currentValue: 16000000,
        from: Carbon::parse('2026-01-15'),
        to: $splitPoint->copy()->subDay(),
    );

    $this->service->generateHistoricalBalances(
        $splitAccount,
        purchasePrice: 10000000,
        purchaseDate: Carbon::parse('2026-01-15'),
        currentValue: 16000000,
        from: $splitPoint,
    );

    $fullBalances = $fullAccount->balances()->orderBy('balance_date')->get();
    $splitBalances = $splitAccount->balances()->orderBy('balance_date')->get();

    expect($splitBalances->pluck('balance_date')->map->toDateString()->toArray())
        ->toBe($fullBalances->pluck('balance_date')->map->toDateString()->toArray());

    expect($splitBalances->pluck('balance')->toArray())
        ->toBe($fullBalances->pluck('balance')->toArray());
});
