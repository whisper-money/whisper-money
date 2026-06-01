<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);
});

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
    $response
        ->assertRedirect(route('onboarding', absolute: false))
        ->assertCookie('whisper_money_returning_user');
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
    $response
        ->assertRedirect(route('onboarding', absolute: false))
        ->assertCookie('whisper_money_returning_user');
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

test('new users can register with a legacy timezone alias', function () {
    Queue::fake();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'timezone' => 'Asia/Calcutta',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding', absolute: false));

    expect(User::where('email', 'test@example.com')->first()->timezone)
        ->toBe('Asia/Calcutta');
});

test('new users can register when the browser sends an unrecognized timezone', function () {
    Queue::fake();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'timezone' => 'Not/AZone',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding', absolute: false));

    expect(User::where('email', 'test@example.com')->first()->timezone)
        ->toBeNull();
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

test('new users can register with the email of a deleted user', function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 4, 22)->setTime(10, 9, 56));

    $deletedUser = User::factory()->create(['email' => 'test@example.com']);
    $deletedUser->markAsDeleted();

    $response = $this->post(route('register.store'), [
        'name' => 'New User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding', absolute: false));

    expect(User::query()->where('email', 'test@example.com')->count())->toBe(1)
        ->and(User::withTrashed()->find($deletedUser->id)?->email)
        ->toBe('20260422100956_test@example.com');
});
