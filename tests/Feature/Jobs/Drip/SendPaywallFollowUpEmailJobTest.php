<?php

use App\Jobs\Drip\SendPaywallFollowUpEmailJob;
use App\Mail\Drip\PaywallFollowUpEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    config(['subscriptions.enabled' => true]);
});

test('paywall follow-up email is sent to users who saw the paywall without connecting a bank', function () {
    $user = User::factory()->onboarded()->paywallSeen()->create();

    SendPaywallFollowUpEmailJob::dispatchSync($user);

    Mail::assertQueued(PaywallFollowUpEmail::class, fn ($mail) => $mail->hasTo($user->email));
});

test('paywall follow-up email is not sent to users who never saw the paywall', function () {
    $user = User::factory()->onboarded()->create(['paywall_seen_at' => null]);

    SendPaywallFollowUpEmailJob::dispatchSync($user);

    Mail::assertNotQueued(PaywallFollowUpEmail::class);
});

test('paywall follow-up email is not sent to pro users', function () {
    $user = User::factory()->onboarded()->paywallSeen()->subscribed()->create();

    SendPaywallFollowUpEmailJob::dispatchSync($user);

    Mail::assertNotQueued(PaywallFollowUpEmail::class);
});
