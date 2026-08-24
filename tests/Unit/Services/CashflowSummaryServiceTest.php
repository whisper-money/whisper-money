<?php

use App\Services\CashflowSummaryService;

it('derives net and savings rate from income and expense', function () {
    expect(CashflowSummaryService::summarize(100000, 40000))->toBe([
        'income' => 100000,
        'expense' => 40000,
        'net' => 60000,
        'savings_rate' => 60.0,
    ]);
});

it('returns a negative net when expense exceeds income', function () {
    expect(CashflowSummaryService::summarize(0, 10000))->toBe([
        'income' => 0,
        'expense' => 10000,
        'net' => -10000,
        'savings_rate' => 0,
    ]);
});

it('avoids division by zero when income is zero', function () {
    $summary = CashflowSummaryService::summarize(0, 0);

    expect($summary['savings_rate'])->toBe(0)
        ->and($summary['net'])->toBe(0);
});

it('rounds the savings rate to one decimal place', function () {
    // (100000 - 33333) / 100000 * 100 = 66.667 -> 66.7
    expect(CashflowSummaryService::summarize(100000, 33333)['savings_rate'])->toBe(66.7);
});
