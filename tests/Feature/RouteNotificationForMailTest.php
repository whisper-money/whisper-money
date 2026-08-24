<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;

test('user returns email for mail routing when valid', function () {
    $user = User::factory()->create(['email' => 'valid@example.com']);

    expect($user->routeNotificationForMail(new VerifyEmailNotification))
        ->toBe('valid@example.com');
});

test('user skips mail routing when email is malformed', function () {
    $user = User::factory()->create();
    $user->forceFill(['email' => 'not-an-email'])->saveQuietly();

    expect($user->routeNotificationForMail(new VerifyEmailNotification))
        ->toBeNull();
});

test('user skips mail routing when email has surrounding whitespace it cannot parse', function () {
    $user = User::factory()->create();
    $user->forceFill(['email' => ' '])->saveQuietly();

    expect($user->routeNotificationForMail(new VerifyEmailNotification))
        ->toBeNull();
});

test('user trims valid email before routing', function () {
    $user = User::factory()->create();
    $user->forceFill(['email' => "  spaced@example.com\n"])->saveQuietly();

    expect($user->routeNotificationForMail(new VerifyEmailNotification))
        ->toBe('spaced@example.com');
});
