<?php

use App\Models\Account;
use App\Models\ForecastItem;
use App\Models\ForecastItemVersion;
use App\Models\ForecastMatch;
use App\Models\User;
use App\Services\Forecast\ForecastDrift;

function paidForecastOccurrence(ForecastItem $item, Account $account, string $occurrenceDate, int $paid, int $planned): ForecastMatch
{
    $payment = forecastCharge($account, $occurrenceDate, $paid, 'RECIBO');

    return ForecastMatch::factory()->create([
        'forecast_item_id' => $item->id,
        'occurrence_date' => $occurrenceDate,
        'transaction_id' => $payment->id,
        'deviation' => $item->account_id === $account->id ? $paid - $planned : -$paid - $planned,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $this->gym = ForecastItem::factory()->amount(-4000)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'anchor_date' => '2026-01-05',
        'amount_tolerance' => 200,
    ]);
});

it('suggests updating a line after two payments in a row beyond its tolerance', function () {
    paidForecastOccurrence($this->gym, $this->account, '2026-08-05', -4000, -4000);
    paidForecastOccurrence($this->gym, $this->account, '2026-09-05', -4500, -4000);
    paidForecastOccurrence($this->gym, $this->account, '2026-10-05', -4600, -4000);

    $suggestion = app(ForecastDrift::class)->suggestionFor($this->gym);

    expect($suggestion?->toArray())->toBe(['itemId' => $this->gym->id, 'amount' => -4600, 'since' => '2026-09-05']);
});

it('suggests nothing after a single deviating payment', function () {
    paidForecastOccurrence($this->gym, $this->account, '2026-09-05', -4000, -4000);
    paidForecastOccurrence($this->gym, $this->account, '2026-10-05', -4600, -4000);

    expect(app(ForecastDrift::class)->suggestionFor($this->gym))->toBeNull();
});

it('suggests nothing for deviations within tolerance or in opposite directions', function () {
    paidForecastOccurrence($this->gym, $this->account, '2026-09-05', -4200, -4000);
    paidForecastOccurrence($this->gym, $this->account, '2026-10-05', -4150, -4000);
    expect(app(ForecastDrift::class)->suggestionFor($this->gym))->toBeNull();

    paidForecastOccurrence($this->gym, $this->account, '2026-11-05', -3000, -4000);
    paidForecastOccurrence($this->gym, $this->account, '2026-12-05', -5000, -4000);
    expect(app(ForecastDrift::class)->suggestionFor($this->gym))->toBeNull();
});

it('stops suggesting once the user updated the amount', function () {
    paidForecastOccurrence($this->gym, $this->account, '2026-09-05', -4500, -4000);
    paidForecastOccurrence($this->gym, $this->account, '2026-10-05', -4500, -4000);
    ForecastItemVersion::factory()->create(['forecast_item_id' => $this->gym->id, 'effective_from' => '2026-11-05', 'amount' => -4500]);

    expect(app(ForecastDrift::class)->suggestionFor($this->gym->fresh()))->toBeNull();
});

it('reads a transfer from its sending leg', function () {
    $savings = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $transfer = ForecastItem::factory()->amount(-30000)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'destination_account_id' => $savings->id,
        'anchor_date' => '2026-01-02',
        'amount_tolerance' => 1000,
    ]);
    paidForecastOccurrence($transfer, $this->account, '2026-09-02', -35000, -30000);
    paidForecastOccurrence($transfer, $savings, '2026-09-02', 35000, -30000);
    paidForecastOccurrence($transfer, $this->account, '2026-10-02', -35000, -30000);

    expect(app(ForecastDrift::class)->suggestions(ForecastItem::query()->get()))
        ->toHaveKey($transfer->id)
        ->and(app(ForecastDrift::class)->suggestionFor($transfer)->amount)->toBe(-35000);
});
