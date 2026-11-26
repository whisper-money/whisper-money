<?php

use App\Enums\AccountType;
use App\Services\Forecast\ForecastAlertEvaluator;

function alertMonth(string $month, int $min, int $pessimisticMin, string $minDate = '-15'): array
{
    return ['month' => $month, 'min' => $min, 'minDate' => $month.$minDate, 'pessimisticMin' => $pessimisticMin];
}

it('reads the lowest month below zero as a strong problem', function () {
    $months = [alertMonth('2026-12', 5000, 1000), alertMonth('2027-01', -200, -900), alertMonth('2027-02', -1500, -2000, '-27')];

    expect((new ForecastAlertEvaluator)->evaluate($months, 0))->toBe([
        'level' => 'strong',
        'month' => '2027-02',
        'min' => -1500,
        'minDate' => '2027-02-27',
        'softMonths' => [],
    ]);
});

it('reads a month below the wanted minimum as a strong problem', function () {
    $months = [alertMonth('2026-12', 20000, 15000)];

    expect((new ForecastAlertEvaluator)->evaluate($months, 30000)['level'])->toBe('strong');
});

it('reads a month that only the worst case takes below the threshold as a soft problem', function () {
    $months = [alertMonth('2026-12', 5000, -100), alertMonth('2027-01', 9000, 2000)];

    expect((new ForecastAlertEvaluator)->evaluate($months, 0))->toMatchArray([
        'level' => 'soft',
        'month' => null,
        'softMonths' => ['2026-12'],
    ]);
});

it('reads months that hold up even in the worst case as fine', function () {
    expect((new ForecastAlertEvaluator)->evaluate([alertMonth('2026-12', 5000, 100)], 0)['level'])->toBe('ok');
});

it('counts the months with a strong problem', function () {
    $months = [alertMonth('2026-12', -1, -1), alertMonth('2027-01', 100, -50), alertMonth('2027-02', 400, 400)];

    expect((new ForecastAlertEvaluator)->strongCount($months, 0))->toBe(1)
        ->and((new ForecastAlertEvaluator)->strongCount($months, 500))->toBe(3);
});

it('never warns without a threshold', function () {
    $months = [alertMonth('2026-12', -90000, -95000)];

    expect((new ForecastAlertEvaluator)->evaluate($months, null)['level'])->toBe('ok')
        ->and((new ForecastAlertEvaluator)->strongCount($months, null))->toBe(0);
});

it('holds an account to its wanted minimum, else to zero, except a card or a loan, which live below zero', function (AccountType $type, ?int $minimum, ?int $threshold) {
    expect((new ForecastAlertEvaluator)->threshold($type, $minimum))->toBe($threshold);
})->with([
    'checking' => [AccountType::Checking, null, 0],
    'checking with a minimum' => [AccountType::Checking, 30000, 30000],
    'savings' => [AccountType::Savings, null, 0],
    'credit card' => [AccountType::CreditCard, null, null],
    'loan' => [AccountType::Loan, null, null],
    'credit card with a minimum' => [AccountType::CreditCard, -100000, -100000],
]);
