<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;

test('user returns email for mail routing when valid', function () {
    $user = User::factory()->create(['email' => 'valid@example.com']);

    expect($user->routeNotificationForMail(new VerifyEmailNotification))
        ->toBe('valid@example.com');
});

test('user skips mail routing when the email cannot be parsed', function (string $email) {
    $user = User::factory()->create();
    $user->forceFill(['email' => $email])->saveQuietly();

    expect($user->routeNotificationForMail(new VerifyEmailNotification))
        ->toBeNull();
})->with([
    'malformed' => ['not-an-email'],
    'only whitespace' => [' '],
]);

test('user trims valid email before routing', function () {
    $user = User::factory()->create();
    $user->forceFill(['email' => "  spaced@example.com\n"])->saveQuietly();

    expect($user->routeNotificationForMail(new VerifyEmailNotification))
        ->toBe('spaced@example.com');
});
