<?php

use App\Support\SortedDates;

it('finds the last date on or before the one asked about', function (array $dates, string $date, int $expected) {
    expect(SortedDates::lastIndexOnOrBefore($dates, $date))->toBe($expected);
})->with([
    'no dates' => [[], '2026-01-15', -1],
    'every date after it' => [['2026-01-10', '2026-01-20'], '2026-01-05', -1],
    'exact match on the first' => [['2026-01-10', '2026-01-20'], '2026-01-10', 0],
    'between two dates' => [['2026-01-10', '2026-01-20'], '2026-01-15', 0],
    'exact match on the last' => [['2026-01-10', '2026-01-20'], '2026-01-20', 1],
    'past every date' => [['2026-01-10', '2026-01-20'], '2026-02-01', 1],
    'across a month boundary' => [['2025-12-31', '2026-01-01', '2026-01-02'], '2026-01-01', 1],
    'single date before' => [['2026-01-10'], '2026-01-09', -1],
    'single date on' => [['2026-01-10'], '2026-01-10', 0],
]);

it('agrees with a linear scan on a long list', function () {
    $dates = [];
    for ($day = 0; $day < 400; $day += 3) {
        $dates[] = date('Y-m-d', strtotime("2025-01-01 +{$day} days"));
    }

    for ($day = -5; $day < 410; $day++) {
        $date = date('Y-m-d', strtotime("2025-01-01 +{$day} days"));
        $linear = -1;
        foreach ($dates as $index => $candidate) {
            if ($candidate <= $date) {
                $linear = $index;
            }
        }

        expect(SortedDates::lastIndexOnOrBefore($dates, $date))->toBe($linear);
    }
});
