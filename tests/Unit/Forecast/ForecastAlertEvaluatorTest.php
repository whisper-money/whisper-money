<?php

use App\Services\Forecast\ForecastAlertEvaluator;

function alertMonth(string $month, int $min, int $pessimisticMin, string $minDate = '-15'): array
{
    return ['month' => $month, 'min' => $min, 'minDate' => $month.$minDate, 'pessimisticMin' => $pessimisticMin];
}

it('reads the lowest month below zero as a strong problem', function () {
    $months = [alertMonth('2026-12', 5000, 1000), alertMonth('2027-01', -200, -900), alertMonth('2027-02', -1500, -2000, '-27')];

    expect((new ForecastAlertEvaluator)->evaluate($months, null))->toBe([
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

    expect((new ForecastAlertEvaluator)->evaluate($months, null))->toMatchArray([
        'level' => 'soft',
        'month' => null,
        'softMonths' => ['2026-12'],
    ]);
});

it('reads months that hold up even in the worst case as fine', function () {
    expect((new ForecastAlertEvaluator)->evaluate([alertMonth('2026-12', 5000, 100)], null)['level'])->toBe('ok');
});

it('counts the months with a strong problem', function () {
    $months = [alertMonth('2026-12', -1, -1), alertMonth('2027-01', 100, -50), alertMonth('2027-02', 400, 400)];

    expect((new ForecastAlertEvaluator)->strongCount($months, null))->toBe(1)
        ->and((new ForecastAlertEvaluator)->strongCount($months, 500))->toBe(3);
});
