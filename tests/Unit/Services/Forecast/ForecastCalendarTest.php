<?php

use App\Services\Forecast\ForecastCalendar;

it('adds calendar months and clamps to the month\'s last day', function (string $anchor, int $months, string $expected) {
    expect(ForecastCalendar::addMonths($anchor, $months))->toBe($expected);
})->with([
    ['2027-01-31', 1, '2027-02-28'],
    ['2027-01-31', 2, '2027-03-31'],
    ['2024-02-29', 12, '2025-02-28'],
    ['2024-02-29', 48, '2028-02-29'],
    ['2027-11-15', 3, '2028-02-15'],
]);

it('ends a horizon on the last day of its last month', function () {
    expect(ForecastCalendar::horizonEnd('2027-04-17', 1))->toBe('2027-04-30')
        ->and(ForecastCalendar::horizonEnd('2027-11-02', 4))->toBe('2028-02-29');
});
