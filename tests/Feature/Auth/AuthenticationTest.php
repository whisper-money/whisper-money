<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

test('login screen can be rendered with an intended destination in session', function () {
    $response = $this
        ->withSession(['url.intended' => route('dashboard')])
        ->get(route('login'));

    $response->assertSuccessful();
});

test('login screen stays available for returning users with an intended destination', function () {
    $response = $this
        ->withCookie('whisper_money_returning_user', '1')
        ->withSession(['url.intended' => route('dashboard')])
        ->get(route('login'));

    $response->assertSuccessful();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertCookie('whisper_money_returning_user');
});

test('logging in records the last logged in date', function () {
    $user = User::factory()->withoutTwoFactor()->create(['last_logged_in_at' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    expect($user->fresh()->last_logged_in_at)->not->toBeNull();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('deleted users can not authenticate', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    $user->delete();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
