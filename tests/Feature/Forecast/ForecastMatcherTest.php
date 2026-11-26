<?php

use App\Enums\ForecastFrequency;
use App\Enums\ForecastMatchedBy;
use App\Models\Account;
use App\Models\ForecastItem;
use App\Models\ForecastItemOverride;
use App\Models\ForecastMatch;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\ForecastMatcher;

/**
 * The Forecast flag stays off in these tests, so nothing matches on its own:
 * each test calls the matcher itself.
 */
function matchToForecast(Transaction $transaction): ?ForecastMatch
{
    return app(ForecastMatcher::class)->match($transaction->fresh());
}

/**
 * @param  array<string, mixed>  $attributes
 */
function forecastLine(Account $account, int $amount, string $anchorDate, array $attributes = []): ForecastItem
{
    return ForecastItem::factory()->amount($amount)->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'anchor_date' => $anchorDate,
        'date_window_days' => 3,
        'amount_tolerance' => 500,
        ...$attributes,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->checking = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
});

it('pairs a payment with its occurrence and stores the deviation', function () {
    $rent = forecastLine($this->checking, -90000, '2026-01-01', ['match_rules_json' => ['in' => ['alquiler', ['var' => 'description']]]]);
    $payment = forecastCharge($this->checking, '2026-11-02', -90300, 'TRANSFERENCIA ALQUILER NOVIEMBRE');

    $match = matchToForecast($payment);

    expect($match)->not->toBeNull()
        ->and($match->forecast_item_id)->toBe($rent->id)
        ->and($match->occurrence_date->toDateString())->toBe('2026-11-01')
        ->and($match->matched_by)->toBe(ForecastMatchedBy::Auto)
        ->and($match->deviation)->toBe(-300);
});

it('pairs a salary paid early with the next month across the month boundary', function () {
    $salary = forecastLine($this->checking, 250000, '2026-01-01');
    $payment = forecastCharge($this->checking, '2026-09-30', 250000, 'NOMINA');

    $match = matchToForecast($payment);

    expect($match->forecast_item_id)->toBe($salary->id)
        ->and($match->occurrence_date->toDateString())->toBe('2026-10-01');
});

it('leaves a payment outside the date window unmatched', function () {
    forecastLine($this->checking, -90000, '2026-01-01');

    expect(matchToForecast(forecastCharge($this->checking, '2026-11-05', -90000, 'ALQUILER')))->toBeNull();
});

it('leaves a payment beyond the amount tolerance unmatched', function () {
    forecastLine($this->checking, -90000, '2026-01-01', ['amount_tolerance' => 500]);

    expect(matchToForecast(forecastCharge($this->checking, '2026-11-01', -90501, 'ALQUILER')))->toBeNull()
        ->and(matchToForecast(forecastCharge($this->checking, '2026-12-01', -90500, 'ALQUILER')))->not->toBeNull();
});

it('leaves a payment the recognition rule rejects unmatched', function () {
    forecastLine($this->checking, -1299, '2026-01-10', ['match_rules_json' => ['in' => ['netflix', ['var' => 'description']]]]);

    expect(matchToForecast(forecastCharge($this->checking, '2026-11-10', -1299, 'SPOTIFY')))->toBeNull()
        ->and(matchToForecast(forecastCharge($this->checking, '2026-11-10', -1299, 'NETFLIX.COM')))->not->toBeNull();
});

it('ignores lines of other accounts, other users and archived lines', function () {
    $savings = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
    forecastLine($savings, -90000, '2026-01-01');
    forecastLine($this->checking, -90000, '2026-01-01', ['archived_at' => now()]);
    $stranger = Account::factory()->create(['currency_code' => 'EUR']);
    forecastLine($stranger, -90000, '2026-01-01');

    expect(matchToForecast(forecastCharge($this->checking, '2026-11-01', -90000, 'ALQUILER')))->toBeNull();
});

it('picks the closest occurrence when two lines fit', function () {
    forecastLine($this->checking, -5000, '2026-01-03');
    $closer = forecastLine($this->checking, -5000, '2026-01-05');

    expect(matchToForecast(forecastCharge($this->checking, '2026-11-06', -5000, 'RECIBO'))->forecast_item_id)->toBe($closer->id);
});

it('breaks a tie in date by the closer amount', function () {
    forecastLine($this->checking, -5000, '2026-01-05');
    $closer = forecastLine($this->checking, -5200, '2026-01-05');

    expect(matchToForecast(forecastCharge($this->checking, '2026-11-05', -5150, 'RECIBO'))->forecast_item_id)->toBe($closer->id);
});

it('leaves an ambiguous payment for the user when two occurrences are equally close', function () {
    forecastLine($this->checking, -5000, '2026-01-04');
    forecastLine($this->checking, -5000, '2026-01-06');

    expect(matchToForecast(forecastCharge($this->checking, '2026-11-05', -5000, 'RECIBO')))->toBeNull()
        ->and(ForecastMatch::query()->count())->toBe(0);
});

it('lets an occurrence take a single payment, the next one finding no free occurrence', function () {
    forecastLine($this->checking, -5000, '2026-01-05');

    expect(matchToForecast(forecastCharge($this->checking, '2026-11-05', -5000, 'RECIBO')))->not->toBeNull()
        ->and(matchToForecast(forecastCharge($this->checking, '2026-11-06', -5000, 'RECIBO')))->toBeNull();
});

it('moves a second payment on to the next free occurrence its window reaches', function () {
    $weekly = forecastLine($this->checking, -5000, '2026-01-05', ['date_window_days' => 20]);
    $first = matchToForecast(forecastCharge($this->checking, '2026-11-05', -5000, 'RECIBO'));
    $second = matchToForecast(forecastCharge($this->checking, '2026-11-20', -5000, 'RECIBO'));

    expect($first->occurrence_date->toDateString())->toBe('2026-11-05')
        ->and($second->forecast_item_id)->toBe($weekly->id)
        ->and($second->occurrence_date->toDateString())->toBe('2026-12-05');
});

it('keeps one match per transaction when matched again', function () {
    forecastLine($this->checking, -5000, '2026-01-05');
    $payment = forecastCharge($this->checking, '2026-11-05', -5000, 'RECIBO');

    matchToForecast($payment);
    matchToForecast($payment);

    expect(ForecastMatch::query()->where('transaction_id', $payment->id)->count())->toBe(1);
});

it('drops the match when an edit takes the payment out of the window', function () {
    forecastLine($this->checking, -5000, '2026-01-05');
    $payment = forecastCharge($this->checking, '2026-11-05', -5000, 'RECIBO');
    matchToForecast($payment);

    $payment->updateQuietly(['transaction_date' => '2026-11-20']);

    expect(matchToForecast($payment))->toBeNull()
        ->and(ForecastMatch::query()->count())->toBe(0);
});

it('never replaces a pairing the user made', function () {
    $line = forecastLine($this->checking, -5000, '2026-01-05');
    $payment = forecastCharge($this->checking, '2026-11-25', -9999, 'RECIBO');
    ForecastMatch::factory()->create([
        'forecast_item_id' => $line->id,
        'occurrence_date' => '2026-11-05',
        'transaction_id' => $payment->id,
        'matched_by' => ForecastMatchedBy::User,
    ]);

    expect(matchToForecast($payment)->matched_by)->toBe(ForecastMatchedBy::User)
        ->and(ForecastMatch::query()->count())->toBe(1);
});

it('matches both legs of a transfer, the receiving one by amount and date', function () {
    $savings = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $transfer = forecastLine($this->checking, -30000, '2026-01-02', [
        'destination_account_id' => $savings->id,
        'match_rules_json' => ['in' => ['traspaso', ['var' => 'description']]],
    ]);

    $sent = matchToForecast(forecastCharge($this->checking, '2026-11-02', -30000, 'TRASPASO A AHORRO'));
    $received = matchToForecast(forecastCharge($savings, '2026-11-03', 30100, 'INGRESO DESDE CUENTA'));

    expect($sent->forecast_item_id)->toBe($transfer->id)
        ->and($received->forecast_item_id)->toBe($transfer->id)
        ->and($received->occurrence_date->toDateString())->toBe('2026-11-02')
        ->and($received->deviation)->toBe(-100);
});

it('matches an occurrence an override moved, under its scheduled date', function () {
    $line = forecastLine($this->checking, -5000, '2026-01-05');
    ForecastItemOverride::factory()->create(['forecast_item_id' => $line->id, 'occurrence_date' => '2026-11-05', 'moved_to' => '2026-11-15', 'amount' => null]);

    $match = matchToForecast(forecastCharge($this->checking, '2026-11-15', -5000, 'RECIBO'));

    expect($match->occurrence_date->toDateString())->toBe('2026-11-05');
});

it('matches a one-off line once', function () {
    $once = ForecastItem::factory()->frequency(ForecastFrequency::Once)->amount(-40000)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->checking->id,
        'anchor_date' => '2026-11-20',
        'date_window_days' => 5,
    ]);

    expect(matchToForecast(forecastCharge($this->checking, '2026-11-18', -40000, 'IBI'))->forecast_item_id)->toBe($once->id);
});

it('removes the match on unmatch', function () {
    forecastLine($this->checking, -5000, '2026-01-05');
    $payment = forecastCharge($this->checking, '2026-11-05', -5000, 'RECIBO');
    matchToForecast($payment);

    app(ForecastMatcher::class)->unmatch($payment->id);

    expect(ForecastMatch::query()->count())->toBe(0);
});

it('drops a pairing the user made once the transaction moves to an account the line does not touch', function () {
    $line = forecastLine($this->checking, -5000, '2026-01-05');
    $payment = forecastCharge($this->checking, '2026-11-05', -5000, 'RECIBO');
    ForecastMatch::factory()->create([
        'forecast_item_id' => $line->id,
        'occurrence_date' => '2026-11-05',
        'transaction_id' => $payment->id,
        'matched_by' => ForecastMatchedBy::User,
    ]);
    $other = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);

    $payment->updateQuietly(['account_id' => $other->id]);

    expect(matchToForecast($payment))->toBeNull()
        ->and(ForecastMatch::query()->count())->toBe(0);
});

it('matches the part of a split payment that pays the line, the deleted original paying nothing', function () {
    forecastLine($this->checking, -90000, '2026-01-01');
    $original = forecastCharge($this->checking, '2026-11-01', -95000, 'ALQUILER Y PARKING');
    $original->delete();
    $rentPart = forecastCharge($this->checking, '2026-11-01', -90000, 'ALQUILER Y PARKING', attributes: ['split_parent_id' => $original->id]);
    $parkingPart = forecastCharge($this->checking, '2026-11-01', -5000, 'ALQUILER Y PARKING', attributes: ['split_parent_id' => $original->id]);

    expect(app(ForecastMatcher::class)->match(Transaction::withTrashed()->findOrFail($original->id)))->toBeNull()
        ->and(matchToForecast($rentPart))->not->toBeNull()
        ->and(matchToForecast($parkingPart))->toBeNull();
});
