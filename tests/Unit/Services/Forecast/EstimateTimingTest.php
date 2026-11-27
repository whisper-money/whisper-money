<?php

use App\Services\Forecast\EstimateTiming;

const TIMING_MONTHS = ['2026-08', '2026-09', '2026-10'];

it('lands income on the median of the days most of each month\'s income came in', function () {
    $days = [
        '2026-08' => [22 => 250000, 3 => 1000],
        '2026-09' => [29 => 250000],
        '2026-10' => [27 => 250000, 28 => 50000],
    ];

    expect((new EstimateTiming)->incomeDay($days, TIMING_MONTHS))->toBe(27);
});

it('takes the lower middle day of two paydays and has no day without income', function () {
    $timing = new EstimateTiming;

    expect($timing->incomeDay(['2026-09' => [25 => 100], '2026-10' => [28 => 100]], TIMING_MONTHS))->toBe(26)
        ->and($timing->incomeDay(['2026-10' => [28 => -100]], TIMING_MONTHS))->toBeNull()
        ->and($timing->incomeDay([], []))->toBeNull();
});

it('weighs each day of the month by its share of the spend, adding up exactly', function () {
    $profile = (new EstimateTiming)->expenseProfile([
        '2026-08' => [1 => 10000, 15 => 10000],
        '2026-09' => [1 => 10000, 31 => 3333],
        '2026-10' => [15 => -5000],
    ], TIMING_MONTHS);

    expect($profile)->toHaveCount(31)
        ->and(array_sum($profile))->toBe(10000)
        ->and($profile[0])->toBe(7060)
        ->and($profile[14])->toBe(1764)
        ->and($profile[30])->toBe(1176);
});

it('spreads spend evenly with fewer than two months of history or nothing spent', function () {
    $timing = new EstimateTiming;

    expect($timing->expenseProfile(['2026-10' => [5 => 1000]], ['2026-10']))->toBeNull()
        ->and($timing->expenseProfile(['2026-10' => [5 => -1000]], TIMING_MONTHS))->toBeNull();
});
