<?php

use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\artisan;

test('command outputs a signed landing auth link that works on another host', function () {
    config(['landing.hide_auth_buttons' => true]);

    Artisan::call('landing:auth-link', ['--days' => 3]);

    $output = trim(Artisan::output());
    $query = [];
    parse_str(parse_url($output, PHP_URL_QUERY) ?: '', $query);
    $devUrl = 'https://dev.whisper.money.localhost:1355/?'.parse_url($output, PHP_URL_QUERY);

    expect($query['signup'] ?? null)->toBe('1');
    expect($query)->toHaveKeys(['expires', 'signature']);

    $this->withoutVite()->get($devUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('hideAuthButtons', false)
            ->where('canRegister', true)
        );
});

test('command fails for invalid expiration days', function () {
    artisan('landing:auth-link', ['--days' => 0])
        ->expectsOutputToContain('Days must be a positive integer.')
        ->assertFailed();
});
