<?php

use App\Services\CreditCards\StatementSchedule;
use Carbon\CarbonImmutable;

function schedule(string $closing, string $due): StatementSchedule
{
    return new StatementSchedule(CarbonImmutable::parse($closing), CarbonImmutable::parse($due));
}

it('picks the statement charged next', function (string $closing, string $due, string $today, string $expectedFrom, string $expectedClosing, string $expectedDue, bool $isFinal) {
    $next = schedule($closing, $due)->nextPaymentOn(CarbonImmutable::parse($today));

    expect($next['cycle']->toArray())->toBe([
        'period_from' => $expectedFrom,
        'closing_date' => $expectedClosing,
        'due_date' => $expectedDue,
    ])->and($next['is_final'])->toBe($isFinal);
})->with([
    'closing and due in the same month, before closing' => ['2026-03-05', '2026-03-20', '2026-03-03', '2026-02-06', '2026-03-05', '2026-03-20', false],
    'closing and due in the same month, between them' => ['2026-03-05', '2026-03-20', '2026-03-10', '2026-02-06', '2026-03-05', '2026-03-20', true],
    'closing at month end, due early next month' => ['2026-01-31', '2026-02-05', '2026-02-03', '2026-01-01', '2026-01-31', '2026-02-05', true],
    'today is the closing day: the cycle is still open' => ['2026-03-05', '2026-03-20', '2026-03-05', '2026-02-06', '2026-03-05', '2026-03-20', false],
    'today is the due day: still the closed statement' => ['2026-03-05', '2026-03-20', '2026-03-20', '2026-02-06', '2026-03-05', '2026-03-20', true],
    'the day after the due date moves on to the open cycle' => ['2026-03-05', '2026-03-20', '2026-03-21', '2026-03-06', '2026-04-05', '2026-04-20', false],
    'anchor far in the past' => ['2020-01-15', '2020-02-05', '2026-07-01', '2026-05-16', '2026-06-15', '2026-07-05', true],
    'anchor far in the future' => ['2030-01-15', '2030-02-05', '2026-07-01', '2026-05-16', '2026-06-15', '2026-07-05', true],
]);

it('reports the cycle still collecting purchases', function (string $closing, string $due, string $today, string $expectedFrom, string $expectedClosing, string $expectedDue) {
    expect(schedule($closing, $due)->openCycleOn(CarbonImmutable::parse($today))->toArray())->toBe([
        'period_from' => $expectedFrom,
        'closing_date' => $expectedClosing,
        'due_date' => $expectedDue,
    ]);
})->with([
    'before closing' => ['2026-03-05', '2026-03-20', '2026-03-03', '2026-02-06', '2026-03-05', '2026-03-20'],
    'on the closing day' => ['2026-03-05', '2026-03-20', '2026-03-05', '2026-02-06', '2026-03-05', '2026-03-20'],
    'the day after closing' => ['2026-03-05', '2026-03-20', '2026-03-06', '2026-03-06', '2026-04-05', '2026-04-20'],
    'anchor far in the past' => ['2020-01-15', '2020-02-05', '2026-07-01', '2026-06-16', '2026-07-15', '2026-08-05'],
]);

it('keeps a closing on the 31st on the last day of short months without drifting', function (int $offset, string $expectedClosing) {
    expect(schedule('2027-01-31', '2027-02-10')->cycle($offset)->closingDate->toDateString())->toBe($expectedClosing);
})->with([
    'February' => [1, '2027-02-28'],
    'March after February' => [2, '2027-03-31'],
    'April' => [3, '2027-04-30'],
    'May after April' => [4, '2027-05-31'],
    'leap February' => [13, '2028-02-29'],
    'March after the leap February' => [14, '2028-03-31'],
    'backwards into a short month' => [-2, '2026-11-30'],
]);

it('starts each period the day after the previous closing', function () {
    $march = schedule('2027-01-31', '2027-02-10')->cycle(2);

    expect($march->periodFrom->toDateString())->toBe('2027-03-01')
        ->and($march->closingDate->toDateString())->toBe('2027-03-31')
        ->and($march->dueDate->toDateString())->toBe('2027-04-10');
});

it('counts both ends of the period as part of the cycle', function () {
    $cycle = schedule('2026-03-05', '2026-03-20')->cycle(0);

    expect($cycle->contains(CarbonImmutable::parse('2026-02-06')))->toBeTrue()
        ->and($cycle->contains(CarbonImmutable::parse('2026-03-05')))->toBeTrue()
        ->and($cycle->contains(CarbonImmutable::parse('2026-02-05')))->toBeFalse()
        ->and($cycle->contains(CarbonImmutable::parse('2026-03-06')))->toBeFalse();
});
