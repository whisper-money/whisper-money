<?php

use App\Models\Account;
use App\Models\User;
use App\Services\LoanBalanceGeneratorService;
use Carbon\Carbon;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->service = app(LoanBalanceGeneratorService::class);
});

it('generates linearly interpolated balances from start date to today', function () {
    $this->travelTo(Carbon::parse('2026-03-15'));

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
    ]);

    $this->service->generateHistoricalBalances(
        $account,
        originalAmount: 15000000,
        startDate: Carbon::parse('2025-11-15'),
        currentBalance: 12000000,
    );

    $balances = $account->balances()->orderBy('balance_date')->get();

    // start date + Dec 1 + Jan 1 + Feb 1 + Mar 1 + today (Mar 15) = 6
    expect($balances)->toHaveCount(6);

    expect($balances->first()->balance_date->toDateString())->toBe('2025-11-15');
    expect($balances->first()->balance)->toBe(15000000);

    expect($balances->last()->balance_date->toDateString())->toBe('2026-03-15');
    expect($balances->last()->balance)->toBe(12000000);

    // Values strictly decrease (loan being paid down)
    for ($i = 1; $i < $balances->count(); $i++) {
        expect($balances[$i]->balance)->toBeLessThanOrEqual($balances[$i - 1]->balance);
    }
});

it('creates a single balance when start date is today', function () {
    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
    ]);

    $this->service->generateHistoricalBalances(
        $account,
        originalAmount: 20000000,
        startDate: Carbon::today(),
        currentBalance: 20000000,
    );

    $balances = $account->balances;

    expect($balances)->toHaveCount(1);
    expect($balances->first()->balance)->toBe(20000000);
    expect($balances->first()->balance_date->toDateString())->toBe(now()->toDateString());
});

it('does not create balances when start date is in the future', function () {
    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
    ]);

    $this->service->generateHistoricalBalances(
        $account,
        originalAmount: 15000000,
        startDate: Carbon::today()->addMonth(),
        currentBalance: 15000000,
    );

    expect($account->balances)->toHaveCount(0);
});

it('uses upsert to avoid duplicate balance dates', function () {
    $this->travelTo(Carbon::parse('2026-03-15'));

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
    ]);

    $account->balances()->create([
        'balance_date' => '2026-03-15',
        'balance' => 99999999,
    ]);

    $this->service->generateHistoricalBalances(
        $account,
        originalAmount: 15000000,
        startDate: Carbon::parse('2026-02-01'),
        currentBalance: 12000000,
    );

    $todayBalances = $account->balances()->where('balance_date', '2026-03-15')->get();
    expect($todayBalances)->toHaveCount(1);
    expect($todayBalances->first()->balance)->toBe(12000000);
});

it('places intermediate balances on the 1st of each month', function () {
    $this->travelTo(Carbon::parse('2026-04-20'));

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
    ]);

    $this->service->generateHistoricalBalances(
        $account,
        originalAmount: 15000000,
        startDate: Carbon::parse('2026-01-15'),
        currentBalance: 13000000,
    );

    $dates = $account->balances()->orderBy('balance_date')->pluck('balance_date')
        ->map->toDateString()->toArray();

    expect($dates)->toBe([
        '2026-01-15',
        '2026-02-01',
        '2026-03-01',
        '2026-04-01',
        '2026-04-20',
    ]);
});

it('generates only balances within a from/to date range', function () {
    $this->travelTo(Carbon::parse('2026-06-15'));

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
    ]);

    $this->service->generateHistoricalBalances(
        $account,
        originalAmount: 15000000,
        startDate: Carbon::parse('2026-01-15'),
        currentBalance: 9000000,
        from: Carbon::parse('2026-01-15'),
        to: Carbon::parse('2026-02-28'),
    );

    $dates = $account->balances()->orderBy('balance_date')->pluck('balance_date')
        ->map->toDateString()->toArray();

    expect($dates)->toBe([
        '2026-01-15',
        '2026-02-01',
    ]);
});
