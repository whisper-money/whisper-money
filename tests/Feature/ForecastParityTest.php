<?php

use App\Enums\ForecastFrequency;
use App\Services\Forecast\Data\ExpandableItem;
use App\Services\Forecast\Data\Occurrence;
use App\Services\Forecast\Data\ProjectionInput;
use App\Services\Forecast\ForecastCalendar;
use App\Services\Forecast\ForecastProjector;
use App\Services\Forecast\OccurrenceExpander;

/**
 * The same fixtures drive the TS forecast-parity.test.ts, so both engines must
 * agree on every occurrence and every projected figure here: the client
 * recalculates while the user edits, then takes the server's answer.
 *
 * @return array<string, array{0: array<string, mixed>}>
 */
function forecastParityFixtures(): array
{
    $fixtures = json_decode(
        file_get_contents(__DIR__.'/../Fixtures/forecast-parity.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return collect($fixtures)
        ->mapWithKeys(fn (array $fixture): array => [$fixture['name'] => [$fixture]])
        ->all();
}

it('expands and projects the shared parity fixtures the same way the client engine does', function (array $fixture) {
    $input = $fixture['input'];

    $occurrences = (new OccurrenceExpander)->expand(
        array_map(ExpandableItem::fromArray(...), $input['items']),
        $input['startDate'],
        ForecastCalendar::horizonEnd($input['startDate'], $input['horizonMonths']),
    );
    $occurrences = array_map(fn (Occurrence $occurrence): array => $occurrence->toArray(), $occurrences);

    expect($occurrences)->toBe($fixture['expected']['occurrences']);

    $projection = (new ForecastProjector)->project(ProjectionInput::fromArray([...$input, 'occurrences' => $occurrences]));

    expect($projection)->toBe($fixture['expected']['projection']);
})->with(forecastParityFixtures());

it('keeps the mockup scenario figures the design was drawn from', function () {
    $fixture = forecastParityFixtures()['mockup scenario: current account dips to -155 in April, 12 in May, 209 in June'][0];
    $current = $fixture['expected']['projection']['accounts'][0];

    expect($current['accountId'])->toBe('current')
        ->and(array_column($current['months'], 'min'))->toBe([-15500, 1200, 20900])
        ->and(array_column($fixture['expected']['projection']['total'], 'transfers'))->toBe([0, 0, 0]);
});

it('covers every frequency, so a new one cannot drift between the engines unseen', function () {
    $frequencies = collect(forecastParityFixtures())
        ->flatMap(fn (array $fixture): array => array_column($fixture[0]['input']['items'], 'frequency'))
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($frequencies)->toBe(collect(ForecastFrequency::cases())->pluck('value')->sort()->values()->all());
});
