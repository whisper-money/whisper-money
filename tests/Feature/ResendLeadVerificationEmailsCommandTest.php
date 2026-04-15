<?php

use App\Models\UserLead;
use App\Notifications\VerifyUserLeadEmailNotification;
use Illuminate\Support\Facades\Notification;

it('dispatches verification emails only to unverified leads', function () {
    Notification::fake();

    $unverified = UserLead::factory()->count(3)->create(['email_verified_at' => null]);
    UserLead::factory()->create(['email_verified_at' => now()]);

    $this->artisan('leads:resend-verification-emails')
        ->assertSuccessful();

    Notification::assertSentTo($unverified, VerifyUserLeadEmailNotification::class);
    Notification::assertCount(3);
});

it('does not dispatch emails in dry-run mode', function () {
    Notification::fake();

    UserLead::factory()->count(2)->create(['email_verified_at' => null]);

    $this->artisan('leads:resend-verification-emails', ['--dry-run' => true])
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('exits early when there are no unverified leads', function () {
    Notification::fake();

    UserLead::factory()->create(['email_verified_at' => now()]);

    $this->artisan('leads:resend-verification-emails')
        ->expectsOutput('No unverified leads found.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});
