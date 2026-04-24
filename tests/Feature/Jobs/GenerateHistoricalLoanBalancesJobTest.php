<?php

use App\Jobs\GenerateHistoricalLoanBalancesJob;
use App\Models\Account;
use App\Models\User;
use App\Services\LoanBalanceGeneratorService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
});

it('generates balances for the specified date range when dispatched', function () {
    $this->travelTo(Carbon::parse('2026-06-15'));

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
    ]);

    $job = new GenerateHistoricalLoanBalancesJob(
        account: $account,
        originalAmount: 15000000,
        startDate: Carbon::parse('2024-01-15'),
        currentBalance: 9000000,
        from: Carbon::parse('2024-01-15'),
        to: Carbon::parse('2025-05-31'),
    );

    $job->handle(app(LoanBalanceGeneratorService::class));

    $balances = $account->balances()->orderBy('balance_date')->get();

    foreach ($balances as $balance) {
        expect($balance->balance_date->gte(Carbon::parse('2024-01-15')))->toBeTrue();
        expect($balance->balance_date->lte(Carbon::parse('2025-05-31')))->toBeTrue();
    }

    expect($balances->first()->balance_date->toDateString())->toBe('2024-01-15');
    expect($balances->first()->balance)->toBe(15000000);

    $dates = $balances->pluck('balance_date')->map->toDateString()->toArray();
    expect($dates)->not->toContain('2026-06-15');
    expect($dates)->not->toContain('2025-06-01');
});

it('implements ShouldQueue', function () {
    expect(GenerateHistoricalLoanBalancesJob::class)
        ->toImplement(ShouldQueue::class);
});
