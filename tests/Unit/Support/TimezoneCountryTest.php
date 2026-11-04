<?php

use App\Support\TimezoneCountry;

test('a timezone resolves to the country it sits in', function (?string $timezone, ?string $country) {
    expect(TimezoneCountry::of($timezone))->toBe($country);
})->with([
    'Spain' => ['Europe/Madrid', 'ES'],
    'the Canary Islands' => ['Atlantic/Canary', 'ES'],
    'Mexico' => ['America/Mexico_City', 'MX'],
    'a legacy alias' => ['Asia/Calcutta', 'IN'],
    'a zone with no country' => ['UTC', null],
    'an unknown zone' => ['Not/AZone', null],
    'an empty string' => ['', null],
    'null' => [null, null],
]);
