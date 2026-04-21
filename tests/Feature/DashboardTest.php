<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);
});

test('new guests are redirected to the registration page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('register'));
});

test('returning guests are redirected to the login page', function () {
    $this
        ->withCookie('whisper_money_returning_user', '1')
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('new guests are redirected to the login page when registration is disabled', function () {
    config([
        'fortify.features' => array_values(array_filter(
            config('fortify.features'),
            fn (string $feature): bool => $feature !== Features::registration(),
        )),
    ]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('new guests are redirected to the login page when auth buttons are hidden', function () {
    config(['landing.hide_auth_buttons' => true]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('new guests with landing auth override are redirected to the registration page', function () {
    config(['landing.hide_auth_buttons' => true]);

    $this
        ->withCookie(config('landing.auth_override.cookie_name'), '1')
        ->get(route('dashboard'))
        ->assertRedirect(route('register'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $this->get(route('dashboard'))->assertOk();
});
