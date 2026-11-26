<?php

use App\Enums\ForecastFrequency;
use App\Services\Forecast\Data\ExpandableItem;
use App\Services\Forecast\Data\ItemOverride;
use App\Services\Forecast\Data\ItemVersion;
use App\Services\Forecast\Data\Occurrence;
use App\Services\Forecast\OccurrenceExpander;

/**
 * @param  list<ItemVersion>|null  $versions
 * @param  list<ItemOverride>  $overrides
 */
function expandableItem(
    ForecastFrequency $frequency = ForecastFrequency::Monthly,
    string $anchor = '2027-01-15',
    ?string $endsOn = null,
    ?array $versions = null,
    array $overrides = [],
    string $id = 'item',
): ExpandableItem {
    return new ExpandableItem($id, 'account', null, $frequency, $anchor, $endsOn, $versions ?? [new ItemVersion($anchor, -1000)], $overrides);
}

/**
 * @return list<array{0: string, 1: int}>
 */
function occurrenceDatesAndAmounts(ExpandableItem $item, string $from, string $to): array
{
    return array_map(
        fn (Occurrence $occurrence): array => [$occurrence->date, $occurrence->amount],
        (new OccurrenceExpander)->expand([$item], $from, $to),
    );
}

it('repeats a line every period of its frequency from the anchor date', function (ForecastFrequency $frequency, array $expected) {
    $dates = array_column(occurrenceDatesAndAmounts(expandableItem($frequency), '2027-01-01', '2027-12-31'), 0);

    expect($dates)->toBe($expected);
})->with([
    'monthly' => [ForecastFrequency::Monthly, ['2027-01-15', '2027-02-15', '2027-03-15', '2027-04-15', '2027-05-15', '2027-06-15', '2027-07-15', '2027-08-15', '2027-09-15', '2027-10-15', '2027-11-15', '2027-12-15']],
    'bimonthly' => [ForecastFrequency::Bimonthly, ['2027-01-15', '2027-03-15', '2027-05-15', '2027-07-15', '2027-09-15', '2027-11-15']],
    'quarterly' => [ForecastFrequency::Quarterly, ['2027-01-15', '2027-04-15', '2027-07-15', '2027-10-15']],
    'yearly' => [ForecastFrequency::Yearly, ['2027-01-15']],
    'once' => [ForecastFrequency::Once, ['2027-01-15']],
]);

it('clamps an anchor on the 31st to shorter months and returns to the 31st after', function () {
    $dates = array_column(occurrenceDatesAndAmounts(expandableItem(anchor: '2027-01-31'), '2027-01-01', '2027-04-30'), 0);

    expect($dates)->toBe(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30']);
});

it('keeps the schedule of a line anchored before the range', function () {
    $dates = array_column(occurrenceDatesAndAmounts(expandableItem(ForecastFrequency::Bimonthly, '2026-12-10'), '2027-01-01', '2027-04-30'), 0);

    expect($dates)->toBe(['2027-02-10', '2027-04-10']);
});

it('stops a line on its end date', function () {
    $dates = array_column(occurrenceDatesAndAmounts(expandableItem(endsOn: '2027-03-15'), '2027-01-01', '2027-12-31'), 0);

    expect($dates)->toBe(['2027-01-15', '2027-02-15', '2027-03-15']);
});

it('takes the amount from the version in force on each occurrence', function () {
    $item = expandableItem(versions: [
        new ItemVersion('2027-03-01', -2000),
        new ItemVersion('2027-01-15', -1000),
    ]);

    expect(occurrenceDatesAndAmounts($item, '2027-01-01', '2027-04-30'))->toBe([
        ['2027-01-15', -1000],
        ['2027-02-15', -1000],
        ['2027-03-15', -2000],
        ['2027-04-15', -2000],
    ]);
});

it('uses the first version for occurrences before it starts', function () {
    $item = expandableItem(versions: [new ItemVersion('2027-03-01', -2000)]);

    expect(occurrenceDatesAndAmounts($item, '2027-01-01', '2027-01-31'))->toBe([['2027-01-15', -2000]]);
});

it('expands nothing for a line without an amount', function () {
    expect(occurrenceDatesAndAmounts(expandableItem(versions: []), '2027-01-01', '2027-12-31'))->toBe([]);
});

it('applies a this-month-only override: another amount, a skip or a move', function () {
    $item = expandableItem(overrides: [
        new ItemOverride('2027-02-15', amount: -5000),
        new ItemOverride('2027-03-15', skipped: true),
        new ItemOverride('2027-04-15', movedTo: '2027-04-02'),
    ]);

    $occurrences = (new OccurrenceExpander)->expand([$item], '2027-01-01', '2027-04-30');

    expect(array_map(fn (Occurrence $occurrence): array => [$occurrence->date, $occurrence->occurrenceDate, $occurrence->amount], $occurrences))->toBe([
        ['2027-01-15', '2027-01-15', -1000],
        ['2027-02-15', '2027-02-15', -5000],
        ['2027-04-02', '2027-04-15', -1000],
    ]);
});

it('brings an occurrence moved from after the range into it, and drops one moved out', function () {
    $item = expandableItem(anchor: '2027-01-01', overrides: [
        new ItemOverride('2027-02-01', movedTo: '2027-01-30'),
        new ItemOverride('2027-01-01', movedTo: '2026-12-31'),
    ]);

    expect(array_column(occurrenceDatesAndAmounts($item, '2027-01-01', '2027-01-31'), 0))->toBe(['2027-01-30']);
});

it('sorts the occurrences of every line by date', function () {
    $occurrences = (new OccurrenceExpander)->expand([
        expandableItem(anchor: '2027-01-20', id: 'b'),
        expandableItem(anchor: '2027-01-05', id: 'a'),
    ], '2027-01-01', '2027-02-28');

    expect(array_map(fn (Occurrence $occurrence): string => "{$occurrence->date} {$occurrence->itemId}", $occurrences))->toBe([
        '2027-01-05 a',
        '2027-01-20 b',
        '2027-02-05 a',
        '2027-02-20 b',
    ]);
});

it('carries a transfer\'s destination account onto its occurrences', function () {
    $item = new ExpandableItem('transfer', 'current', 'savings', ForecastFrequency::Monthly, '2027-01-01', null, [new ItemVersion('2027-01-01', -50000)]);

    $occurrence = (new OccurrenceExpander)->expand([$item], '2027-01-01', '2027-01-31')[0];

    expect($occurrence->destinationAccountId)->toBe('savings')
        ->and($occurrence->isTransfer())->toBeTrue();
});
