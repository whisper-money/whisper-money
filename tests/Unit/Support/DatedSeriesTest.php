<?php

use App\Support\DatedSeries;

it('reads the value of the last day on or before the one asked about', function (array $dates, string $date, ?int $expected) {
    $series = new DatedSeries($dates, array_map(fn (int $index): int => ($index + 1) * 100, array_keys($dates)));

    expect($series->valueOnOrBefore($date))->toBe($expected);
})->with([
    'no days' => [[], '2026-01-15', null],
    'every day after it' => [['2026-01-10', '2026-01-20'], '2026-01-05', null],
    'exact match on the first' => [['2026-01-10', '2026-01-20'], '2026-01-10', 100],
    'between two days' => [['2026-01-10', '2026-01-20'], '2026-01-15', 100],
    'exact match on the last' => [['2026-01-10', '2026-01-20'], '2026-01-20', 200],
    'past every day' => [['2026-01-10', '2026-01-20'], '2026-02-01', 200],
    'across a year boundary' => [['2025-12-31', '2026-01-01', '2026-01-02'], '2026-01-01', 200],
    'single day before' => [['2026-01-10'], '2026-01-09', null],
    'single day on' => [['2026-01-10'], '2026-01-10', 100],
]);

it('agrees with a linear scan on a long series', function () {
    $dates = [];
    for ($day = 0; $day < 400; $day += 3) {
        $dates[] = date('Y-m-d', strtotime("2025-01-01 +{$day} days"));
    }
    $series = new DatedSeries($dates, array_keys($dates));

    for ($day = -5; $day < 410; $day++) {
        $date = date('Y-m-d', strtotime("2025-01-01 +{$day} days"));
        $linear = null;
        foreach ($dates as $index => $candidate) {
            if ($candidate <= $date) {
                $linear = $index;
            }
        }

        expect($series->valueOnOrBefore($date))->toBe($linear);
    }
});

it('exposes its first day and value', function () {
    $series = new DatedSeries(['2026-01-10', '2026-01-20'], [5, 7]);

    expect($series->isEmpty())->toBeFalse()
        ->and($series->firstDate())->toBe('2026-01-10')
        ->and($series->firstValue())->toBe(5);
});

it('is empty without days', function () {
    $series = new DatedSeries;

    expect($series->isEmpty())->toBeTrue()
        ->and($series->firstDate())->toBeNull()
        ->and($series->firstValue())->toBeNull();
});
