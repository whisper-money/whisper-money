<?php

use App\Services\LandingAuthOverrideService;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['landing.hide_auth_buttons' => true]);
});

test('signed landing link unlocks auth buttons and queues override cookie', function () {
    $signedUrl = 'https://dev.whisper.money.localhost:1355'
        .app(LandingAuthOverrideService::class)->signedPath(now()->addHour())
        .'&lang=es';

    $this->withoutVite()->get($signedUrl)
        ->assertOk()
        ->assertCookie(config('landing.auth_override.cookie_name'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('hideAuthButtons', false)
            ->where('canRegister', true)
        );
});

test('unsigned landing link keeps auth buttons hidden', function () {
    $this->withoutVite()->get(route('home', ['signup' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('hideAuthButtons', true)
            ->where('canRegister', false)
        );
});

test('login page allows registration with the override cookie', function () {
    $cookieName = config('landing.auth_override.cookie_name');

    $this->withCookie($cookieName, '1')
        ->withoutVite()
        ->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('canRegister', true)
        );
});

test('new users can register with the override cookie', function () {
    Queue::fake();

    $cookieName = config('landing.auth_override.cookie_name');

    $response = $this->withCookie($cookieName, '1')->post(route('register.store'), [
        'name' => 'Signed Link User',
        'email' => 'signed@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding', absolute: false));
});
