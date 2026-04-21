<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\artisan;

test('command outputs a valid signed landing auth link', function () {
    Artisan::call('landing:auth-link', ['--days' => 3]);

    $output = trim(Artisan::output());
    $query = [];
    parse_str(parse_url($output, PHP_URL_QUERY) ?: '', $query);

    expect($query['signup'] ?? null)->toBe('1');
    expect($query)->toHaveKeys(['expires', 'signature']);
    expect(URL::hasValidSignature(Request::create($output)))->toBeTrue();
});

test('command fails for invalid expiration days', function () {
    artisan('landing:auth-link', ['--days' => 0])
        ->expectsOutputToContain('Days must be a positive integer.')
        ->assertFailed();
});
