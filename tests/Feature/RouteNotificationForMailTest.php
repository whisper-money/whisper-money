<?php

use App\Models\User;
use App\Models\UserLead;
use App\Notifications\VerifyEmailNotification;
use App\Notifications\VerifyUserLeadEmailNotification;

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

test('user lead returns email for mail routing when valid', function () {
    $lead = UserLead::factory()->create(['email' => 'lead@example.com']);

    expect($lead->routeNotificationForMail(new VerifyUserLeadEmailNotification('https://example.com')))
        ->toBe('lead@example.com');
});

test('user lead skips mail routing when email is malformed', function () {
    $lead = UserLead::factory()->create();
    $lead->forceFill(['email' => 'broken@@example'])->saveQuietly();

    expect($lead->routeNotificationForMail(new VerifyUserLeadEmailNotification('https://example.com')))
        ->toBeNull();
});
