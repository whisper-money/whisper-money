<?php

use App\Enums\ForecastFrequency;

it('steps a weekly line 7 days at a time, across the end of a month and of the year', function () {
    expect(array_map(fn (int $period): ?string => ForecastFrequency::Weekly->occurrence('2026-12-24', $period), [0, 1, 2, 10]))
        ->toBe(['2026-12-24', '2026-12-31', '2027-01-07', '2027-03-04']);
});

it('steps the monthly frequencies by calendar months, clamped to a short month', function (ForecastFrequency $frequency, string $second) {
    expect($frequency->occurrence('2027-01-31', 1))->toBe($second);
})->with([
    'monthly' => [ForecastFrequency::Monthly, '2027-02-28'],
    'bimonthly' => [ForecastFrequency::Bimonthly, '2027-03-31'],
    'quarterly' => [ForecastFrequency::Quarterly, '2027-04-30'],
    'yearly' => [ForecastFrequency::Yearly, '2028-01-31'],
]);

it('has no next occurrence for a one-off line', function () {
    expect(ForecastFrequency::Once->occurrence('2027-01-31', 1))->toBeNull();
});

it('counts how many times a year a line happens, 52 for a weekly one', function (ForecastFrequency $frequency, ?int $perYear) {
    expect($frequency->perYear())->toBe($perYear);
})->with([
    'weekly' => [ForecastFrequency::Weekly, 52],
    'monthly' => [ForecastFrequency::Monthly, 12],
    'bimonthly' => [ForecastFrequency::Bimonthly, 6],
    'quarterly' => [ForecastFrequency::Quarterly, 4],
    'yearly' => [ForecastFrequency::Yearly, 1],
    'once' => [ForecastFrequency::Once, null],
]);
