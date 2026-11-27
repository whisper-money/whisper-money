<?php

use App\Services\Forecast\BalanceTrendFitter;
use App\Services\Forecast\Data\BalanceTrend;

it('fits the least-squares slope and the deviation of the residuals in whole minor units', function () {
    // Sums: n 4, x 6, y 700, xx 14, xy 1700, so the slope is 2600 / 20 = 130
    // and the residuals 20, -10, -40, 30 leave sqrt(3000 / 4) = 27.39.
    $trend = (new BalanceTrendFitter)->fit([0 => 0, 1 => 100, 2 => 200, 3 => 400]);

    expect($trend)->toEqual(new BalanceTrend(4, 130, 27))
        ->and($trend->isProjected())->toBeTrue();
});

it('rounds the slope half away from zero, the same way for a falling balance', function () {
    // n 4, x 6, xx 14: y 0, 2, 6, 7 gives xy 35 and y 15, so (140 - 90) / 20 = 2.5.
    $rising = (new BalanceTrendFitter)->fit([0 => 0, 1 => 2, 2 => 6, 3 => 7]);
    $falling = (new BalanceTrendFitter)->fit([0 => 0, 1 => -2, 2 => -6, 3 => -7]);

    expect($rising->monthlySlope)->toBe(3)
        ->and($falling->monthlySlope)->toBe(-3);
});

it('keeps the months a gap leaves out apart on the line', function () {
    // Missing months 1 and 2: a straight +1000 a month still fits exactly.
    $trend = (new BalanceTrendFitter)->fit([0 => 10000, 3 => 13000, 4 => 14000, 7 => 17000]);

    expect($trend)->toEqual(new BalanceTrend(4, 1000, 0));
});

it('draws no line through fewer than four month ends, so the balance stays flat', function () {
    $trend = (new BalanceTrendFitter)->fit([9 => 10000, 10 => 20000, 11 => 30000]);

    expect($trend)->toEqual(new BalanceTrend(3))
        ->and($trend->isProjected())->toBeFalse()
        ->and($trend->toArray())->toBe(['monthly' => null, 'band' => null, 'points' => 3]);
});
