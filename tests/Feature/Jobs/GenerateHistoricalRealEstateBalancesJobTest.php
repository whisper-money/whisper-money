<?php

use App\Jobs\GenerateHistoricalRealEstateBalancesJob;
use App\Models\Account;
use App\Models\User;
use App\Services\RealEstateBalanceGeneratorService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
});

it('generates balances for the specified date range when dispatched', function () {
    $this->travelTo(Carbon::parse('2026-06-15'));

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    $job = new GenerateHistoricalRealEstateBalancesJob(
        account: $account,
        purchasePrice: 10000000,
        purchaseDate: Carbon::parse('2024-01-15'),
        currentValue: 16000000,
        from: Carbon::parse('2024-01-15'),
        to: Carbon::parse('2025-05-31'),
    );

    $job->handle(app(RealEstateBalanceGeneratorService::class));

    $balances = $account->balances()->orderBy('balance_date')->get();

    // Should only contain dates within the specified range
    foreach ($balances as $balance) {
        expect($balance->balance_date->gte(Carbon::parse('2024-01-15')))->toBeTrue();
        expect($balance->balance_date->lte(Carbon::parse('2025-05-31')))->toBeTrue();
    }

    // First balance should be the purchase date
    expect($balances->first()->balance_date->toDateString())->toBe('2024-01-15');
    expect($balances->first()->balance)->toBe(10000000);

    // Should not include today or any dates after the range
    $dates = $balances->pluck('balance_date')->map->toDateString()->toArray();
    expect($dates)->not->toContain('2026-06-15');
    expect($dates)->not->toContain('2025-06-01');
});

it('implements ShouldQueue', function () {
    expect(GenerateHistoricalRealEstateBalancesJob::class)
        ->toImplement(ShouldQueue::class);
});
