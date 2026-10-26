<?php

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

test('an unfaked outbound HTTP request is blocked in the Feature suite', function () {
    Http::get('https://example.com/should-not-be-called');
})->throws(StrayRequestException::class);
