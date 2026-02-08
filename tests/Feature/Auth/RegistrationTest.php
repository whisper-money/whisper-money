<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertSuccessful();
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
