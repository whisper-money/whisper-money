<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\ForecastItem;
use App\Models\ForecastSnapshot;
use App\Models\Space;
use App\Models\User;
use App\Services\Forecast\ForecastSnapshotService;

beforeEach(function () {
    $this->user = User::factory()->create(['timezone' => 'Europe/Madrid']);
    $this->account = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $this->food = forecastExpenseCategory($this->user);
    $this->rent = ForecastItem::factory()->amount(-90000)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'anchor_date' => '2026-01-01',
    ]);

    foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $date) {
        forecastCharge($this->account, $date, -20000, 'MERCADONA', $this->food);
    }
});

it('freezes the occurrences and estimated rest of the month as it opens', function () {
    $this->travelTo('2026-11-01 09:00:00');

    $snapshots = app(ForecastSnapshotService::class)->freezeCurrentMonth($this->user);

    expect($snapshots)->toHaveCount(1);
    $snapshot = $snapshots->first();

    expect($snapshot->month->toDateString())->toBe('2026-11-01')
        ->and($snapshot->space_id)->toBe($this->user->current_space_id)
        ->and($snapshot->payload['month'])->toBe('2026-11')
        ->and($snapshot->payload['occurrences'])->toBe([[
            'itemId' => $this->rent->id,
            'accountId' => $this->account->id,
            'destinationAccountId' => null,
            'date' => '2026-11-01',
            'occurrenceDate' => '2026-11-01',
            'amount' => -90000,
        ]])
        ->and($snapshot->payload['estimates'])->toBe([[
            'accountId' => $this->account->id,
            'categoryId' => $this->food->id,
            'amount' => 20000,
            'basis' => 'median',
        ]]);
});

it('is idempotent: a frozen month is never rewritten', function () {
    $this->travelTo('2026-11-01 09:00:00');
    $first = app(ForecastSnapshotService::class)->freezeCurrentMonth($this->user)->first();

    forecastCharge($this->account, '2026-10-20', -500000, 'MERCADONA', $this->food);
    $this->travelTo('2026-11-15 09:00:00');
    $again = app(ForecastSnapshotService::class)->freezeCurrentMonth($this->user)->first();

    expect($again->id)->toBe($first->id)
        ->and($again->payload)->toEqual($first->payload)
        ->and(ForecastSnapshot::query()->count())->toBe(1);
});

it('opens the month on the owner\'s clock, not the server\'s', function () {
    // 31 October 23:30 UTC is already 1 November in Madrid.
    $this->travelTo(now('UTC')->setDate(2026, 10, 31)->setTime(23, 30));

    expect(app(ForecastSnapshotService::class)->freezeCurrentMonth($this->user)->first()->month->toDateString())->toBe('2026-11-01');

    $buenosAires = User::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
    Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $buenosAires->id, 'currency_code' => 'EUR']);

    // ...and still 31 October in Buenos Aires.
    expect(app(ForecastSnapshotService::class)->freezeCurrentMonth($buenosAires)->first()->month->toDateString())->toBe('2026-10-01');
});

it('freezes one snapshot per space the owner holds accounts in', function () {
    $this->travelTo('2026-11-01 09:00:00');
    $shared = Space::factory()->create(['owner_id' => $this->user->id]);
    Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'space_id' => $shared->id, 'currency_code' => 'EUR']);

    $snapshots = app(ForecastSnapshotService::class)->freezeCurrentMonth($this->user);

    expect($snapshots->pluck('space_id')->sort()->values()->all())->toBe(collect([$this->user->current_space_id, $shared->id])->sort()->values()->all());
});
