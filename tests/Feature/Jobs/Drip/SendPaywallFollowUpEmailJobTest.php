<?php

use App\Jobs\Drip\SendPaywallFollowUpEmailJob;
use App\Mail\Drip\PaywallFollowUpEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    config(['subscriptions.enabled' => true]);
});

test('paywall follow-up email is not sent to users without a bank connection', function () {
    $user = User::factory()->onboarded()->create();

    SendPaywallFollowUpEmailJob::dispatchSync($user);

    Mail::assertNotQueued(PaywallFollowUpEmail::class);
});
