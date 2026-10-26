<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the register view returns 403 when registration is disabled', function () {
    config(['auth.registration_enabled' => false]);

    $this->withoutVite()->get(route('register'))->assertForbidden();
});

test('the register store returns 403 when registration is disabled', function () {
    config(['auth.registration_enabled' => false]);

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
});

test('the landing page advertises registration by default', function () {
    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('canRegister', true)
        );
});

test('the landing page hides registration when registration is disabled', function () {
    config(['auth.registration_enabled' => false]);

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('canRegister', false)
        );
});

test('the login page hides the sign-up link when registration is disabled', function () {
    config(['auth.registration_enabled' => false]);

    $this->withoutVite()->get(route('login'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('canRegister', false)
        );
});
