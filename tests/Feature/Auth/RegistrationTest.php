<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('registration screen can be rendered', function () {
    $response = $this->withoutVite()->get(route('register'));

    $response->assertSuccessful();
});

test('registration screen stays accessible with force query when auth buttons are hidden', function () {
    config(['landing.hide_auth_buttons' => true]);

    $response = $this->withoutVite()->get(route('register', ['force' => 1]));

    $response
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/register')
            ->where('forcedRegistration', true)
            ->where('hideAuthButtons', false)
        );
});

test('registration is blocked when auth buttons are hidden without force query', function () {
    config(['landing.hide_auth_buttons' => true]);

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();
});

test('new users can register with force query when auth buttons are hidden', function () {
    Queue::fake();

    config(['landing.hide_auth_buttons' => true]);

    $response = $this->post(route('register.store', ['force' => 1]), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding', absolute: false));
});

test('new users can register', function () {
    Queue::fake();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding', absolute: false));
});

test('new users store their detected timezone on registration', function () {
    Queue::fake();

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'timezone' => 'America/New_York',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    expect($user->timezone)->toBe('America/New_York');
});

test('new users can register without a timezone', function () {
    Queue::fake();

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    expect($user->timezone)->toBeNull();
});

test('new users receive a verification email on registration', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

test('new users are not verified after registration', function () {
    Queue::fake();

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    expect($user->hasVerifiedEmail())->toBeFalse();
});

test('new users are auto-verified when email verification is disabled', function () {
    Queue::fake();

    config(['mail.email_verification_enabled' => false]);

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    expect($user->hasVerifiedEmail())->toBeTrue();
});
