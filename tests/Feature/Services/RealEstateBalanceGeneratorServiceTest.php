<?php

use App\Models\Account;
use App\Models\User;
use App\Services\RealEstateBalanceGeneratorService;
use Carbon\Carbon;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->service = app(RealEstateBalanceGeneratorService::class);
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
