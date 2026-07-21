<?php

use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);
});

afterEach(function () {
    putenv('REGISTRATION_ENABLED');
    unset($_ENV['REGISTRATION_ENABLED'], $_SERVER['REGISTRATION_ENABLED']);
});

test('registration feature is enabled by default', function () {
    putenv('REGISTRATION_ENABLED');
    unset($_ENV['REGISTRATION_ENABLED'], $_SERVER['REGISTRATION_ENABLED']);

    $config = require base_path('config/fortify.php');

    expect($config['features'])->toContain(Features::registration());
});

test('registration feature is removed when REGISTRATION_ENABLED is false', function () {
    putenv('REGISTRATION_ENABLED=false');
    $_ENV['REGISTRATION_ENABLED'] = 'false';

    $config = require base_path('config/fortify.php');

    expect($config['features'])
        ->not->toContain(Features::registration())
        ->and($config['features'])->toContain(Features::resetPasswords())
        ->and($config['features'])->toContain(Features::emailVerification());
});

test('the landing page advertises registration by default', function () {
    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('canRegister', true)
        );
});

test('the landing page hides registration when the feature is disabled', function () {
    config(['fortify.features' => [
        Features::resetPasswords(),
        Features::emailVerification(),
    ]]);

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('canRegister', false)
        );
});

test('the login page hides the sign-up link when registration is disabled', function () {
    config(['fortify.features' => [
        Features::resetPasswords(),
        Features::emailVerification(),
    ]]);

    $this->get(route('login'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('canRegister', false)
        );
});

test('the register routes are not registered when registration is disabled', function () {
    putenv('REGISTRATION_ENABLED=false');
    $_ENV['REGISTRATION_ENABLED'] = 'false';
    $_SERVER['REGISTRATION_ENABLED'] = 'false';

    $this->refreshApplication();

    expect(app('router')->has('register'))->toBeFalse()
        ->and(app('router')->has('register.store'))->toBeFalse();

    $this->withoutVite()->get('/register')->assertNotFound();

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->withoutVite()->get(route('login'))->assertSuccessful();
});
