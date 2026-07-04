<?php

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

test('an unfaked outbound HTTP request is blocked in the Feature suite', function () {
    Http::get('https://example.com/should-not-be-called');
})->throws(StrayRequestException::class);

test('a faked request still goes through when a matching fake is registered', function () {
    Http::fake(['example.com/*' => Http::response(['ok' => true])]);

    $response = Http::get('https://example.com/allowed');

    expect($response->json('ok'))->toBeTrue();
});
