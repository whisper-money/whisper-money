<?php

use App\Enums\ForecastEstimateKind;
use App\Services\Forecast\Data\MonthlyEstimate;
use App\Services\Forecast\Data\Occurrence;
use App\Services\Forecast\Data\ProjectionAccount;
use App\Services\Forecast\Data\ProjectionInput;
use App\Services\Forecast\ForecastProjector;

/**
 * @param  list<ProjectionAccount>  $accounts
 * @param  list<Occurrence>  $occurrences
 * @param  list<MonthlyEstimate>  $estimates
 * @return array{accounts: list<array{accountId: string, minimumBalance: int|null, months: list<array<string, int|string|null>>}>, total: list<array<string, int|string|null>>}
 */
function forecastProjection(array $accounts, array $occurrences = [], array $estimates = [], string $start = '2027-04-01', int $horizon = 1): array
{
    return (new ForecastProjector)->project(new ProjectionInput($accounts, $occurrences, $estimates, $start, $horizon));
}

function forecastOccurrence(string $date, int $amount, string $account = 'current', ?string $destination = null): Occurrence
{
    return new Occurrence('item', $account, $destination, $date, $date, $amount);
}

it('reports the lowest end-of-day balance of the month, not the close', function () {
    $month = forecastProjection([new ProjectionAccount('current', 100000)], [
        forecastOccurrence('2027-04-01', -150000),
        forecastOccurrence('2027-04-28', 200000),
    ])['accounts'][0]['months'][0];

    expect($month)->toMatchArray([
        'month' => '2027-04',
        'min' => -50000,
        'minDate' => '2027-04-01',
        'close' => 150000,
        'income' => 200000,
        'expenses' => 150000,
    ]);
});

it('nets a payday and a charge on the same day before measuring the minimum', function () {
    $month = forecastProjection([new ProjectionAccount('current', 0)], [
        forecastOccurrence('2027-04-01', -1000),
        forecastOccurrence('2027-04-01', 5000),
    ])['accounts'][0]['months'][0];

    expect($month['min'])->toBe(4000);
});

it('spreads the estimated spend evenly over the month and adds it to the expenses', function () {
    $month = forecastProjection(
        [new ProjectionAccount('current', 30000)],
        estimates: [new MonthlyEstimate('current', '2027-04', 20000), new MonthlyEstimate('current', '2027-04', 10000)],
    )['accounts'][0]['months'][0];

    expect($month)->toMatchArray([
        'close' => 0,
        'min' => 0,
        'estimated' => 30000,
        'expenses' => 30000,
        'band' => 12000,
        'pessimisticMin' => -12000,
    ]);
});

it('spreads the start month\'s estimate over the days left in it', function () {
    $result = forecastProjection(
        [new ProjectionAccount('current', 10000)],
        [forecastOccurrence('2027-04-30', 5000)],
        [new MonthlyEstimate('current', '2027-04', 10000)],
        start: '2027-04-21',
    );

    expect($result['accounts'][0]['months'][0])->toMatchArray(['min' => 1000, 'minDate' => '2027-04-29', 'close' => 5000]);
});

it('sizes the band from each month\'s estimate alone, never carried over', function () {
    $months = forecastProjection(
        [new ProjectionAccount('current', 0)],
        estimates: [new MonthlyEstimate('current', '2027-04', 50000)],
        horizon: 2,
    )['accounts'][0]['months'];

    expect(array_column($months, 'band'))->toBe([20000, 0])
        ->and($months[1]['pessimisticMin'])->toBe(-50000);
});

it('moves a transfer between both accounts and leaves the total untouched', function () {
    $result = forecastProjection(
        [new ProjectionAccount('current', 100000), new ProjectionAccount('savings', 0)],
        [forecastOccurrence('2027-04-01', -40000, destination: 'savings')],
    );

    expect($result['accounts'][0]['months'][0])->toMatchArray(['close' => 60000, 'transfers' => -40000, 'expenses' => 0])
        ->and($result['accounts'][1]['months'][0])->toMatchArray(['close' => 40000, 'transfers' => 40000, 'income' => 0]);
    expect($result['total'][0])->toMatchArray(['close' => 100000, 'min' => 100000, 'transfers' => 0, 'income' => 0, 'expenses' => 0]);
});

it('takes the total minimum from the summed daily balances', function () {
    $result = forecastProjection(
        [new ProjectionAccount('a', 0), new ProjectionAccount('b', 0)],
        [forecastOccurrence('2027-04-05', -1000, 'a'), forecastOccurrence('2027-04-05', 1000, 'a'), forecastOccurrence('2027-04-10', -2000, 'b'), forecastOccurrence('2027-04-20', 3000, 'a')],
    );

    expect($result['total'][0])->toMatchArray(['min' => -2000, 'minDate' => '2027-04-10', 'close' => 1000]);
});

it('grows an account by a twelfth of its annual rate on every whole month', function () {
    $months = forecastProjection([new ProjectionAccount('broker', 1200000, annualRateBps: 500)], start: '2027-04-10', horizon: 3)['accounts'][0]['months'];

    expect(array_column($months, 'growth'))->toBe([0, 5000, 5020])
        ->and(array_column($months, 'close'))->toBe([1200000, 1205000, 1210020]);
});

it('carries each account\'s desired minimum balance through', function () {
    $result = forecastProjection([new ProjectionAccount('current', 0, minimumBalance: 20000)]);

    expect($result['accounts'][0]['minimumBalance'])->toBe(20000);
});

it('projects the start month and the horizon after it', function () {
    $result = forecastProjection([new ProjectionAccount('current', 0)], start: '2027-11-20', horizon: 3);

    expect(array_column($result['total'], 'month'))->toBe(['2027-11', '2027-12', '2028-01']);
});

it('lands estimated income whole on its day and adds it to the income', function () {
    $month = forecastProjection(
        [new ProjectionAccount('current', 10000)],
        estimates: [
            new MonthlyEstimate('current', '2027-04', 250000, ForecastEstimateKind::Income, 'salary', 27),
            new MonthlyEstimate('current', '2027-04', 30000),
        ],
    )['accounts'][0]['months'][0];

    expect($month)->toMatchArray([
        'min' => -16000,
        'minDate' => '2027-04-26',
        'close' => 230000,
        'income' => 250000,
        'expenses' => 30000,
        'estimated' => 30000,
        'estimatedIncome' => 250000,
    ]);
});

it('widens the band by 40% of the estimated spend and 10% of the estimated income', function () {
    $month = forecastProjection(
        [new ProjectionAccount('current', 0)],
        estimates: [
            new MonthlyEstimate('current', '2027-04', 200000, ForecastEstimateKind::Income, null, 1),
            new MonthlyEstimate('current', '2027-04', 50000),
        ],
    )['accounts'][0]['months'][0];

    expect($month['band'])->toBe(20000 + 20000)
        ->and($month['pessimisticMin'])->toBe($month['min'] - 40000);
});
