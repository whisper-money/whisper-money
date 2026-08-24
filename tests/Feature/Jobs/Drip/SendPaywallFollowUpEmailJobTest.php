<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendPaywallFollowUpEmailJob;
use App\Mail\Drip\PaywallFollowUpEmail;
use App\Models\BankingConnection;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    config(['subscriptions.enabled' => true]);
});

test('paywall follow-up email is sent to a stuck user', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->for($user)->create();

    SendPaywallFollowUpEmailJob::dispatchSync($user);

    Mail::assertQueued(PaywallFollowUpEmail::class, fn ($mail) => $mail->hasTo($user->email));

    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::PaywallFollowUp->value,
    ]);
});

test('paywall follow-up email is not sent to users with a pro plan', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->for($user)->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    SendPaywallFollowUpEmailJob::dispatchSync($user);

    Mail::assertNotQueued(PaywallFollowUpEmail::class);
});

test('paywall follow-up email is not sent to users without a bank connection', function () {
    $user = User::factory()->onboarded()->create();

    SendPaywallFollowUpEmailJob::dispatchSync($user);

    Mail::assertNotQueued(PaywallFollowUpEmail::class);
});

test('paywall follow-up email is not sent if already received', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->for($user)->create();
    UserMailLog::factory()->for($user)->paywallFollowUp()->create();

    SendPaywallFollowUpEmailJob::dispatchSync($user);

    Mail::assertNotQueued(PaywallFollowUpEmail::class);
});
