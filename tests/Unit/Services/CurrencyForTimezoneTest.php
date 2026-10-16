<?php

use App\Services\CurrencyOptions;
use Tests\TestCase;

uses(TestCase::class);

test('a timezone resolves to the currency of its country', function (?string $timezone, ?string $currency) {
    expect(app(CurrencyOptions::class)->forTimezone($timezone))->toBe($currency);
})->with([
    'India' => ['Asia/Kolkata', 'INR'],
    'the Canary Islands' => ['Atlantic/Canary', 'EUR'],
    'Liechtenstein' => ['Europe/Vaduz', 'CHF'],
    'Argentina' => ['America/Argentina/Buenos_Aires', 'ARS'],
    'a zone with no country' => ['UTC', null],
    'a legacy alias' => ['Asia/Calcutta', 'INR'],
    'a country we hold no currency for' => ['Asia/Dhaka', null],
    'an unknown zone' => ['Not/AZone', null],
    'an empty string' => ['', null],
    'null' => [null, null],
]);
