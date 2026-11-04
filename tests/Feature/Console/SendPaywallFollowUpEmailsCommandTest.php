<?php

use App\Enums\DripEmailType;
use App\Mail\Drip\PaywallFollowUpEmail;
use App\Models\BankingConnection;
use App\Models\User;
use App\Models\UserMailLog;
use App\Models\UserSetting;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    config([
        'subscriptions.enabled' => true,
        'mail.drip_emails_enabled' => true,
    ]);
});

function stuckPaywallUser(): User
{
    return User::factory()->paywallSeen()->create(['onboarded_at' => now()->subDay()]);
}

test('it sends the follow-up email to users onboarded yesterday who saw the paywall and have no pro plan', function () {
    $user = stuckPaywallUser();

    $this->artisan('email:paywall-follow-up')->assertSuccessful();

    Mail::assertQueued(PaywallFollowUpEmail::class, fn ($mail) => $mail->hasTo($user->email));

    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::PaywallFollowUp->value,
    ]);
});

test('it does not send to users who onboarded today or earlier than yesterday', function () {
    User::factory()->paywallSeen()->create(['onboarded_at' => now()]);
    User::factory()->paywallSeen()->create(['onboarded_at' => now()->subDays(2)]);

    $this->artisan('email:paywall-follow-up')->assertSuccessful();

    Mail::assertNotQueued(PaywallFollowUpEmail::class);
});

test('it sends to users who never connected a bank', function () {
    $user = stuckPaywallUser();

    expect($user->bankingConnections()->exists())->toBeFalse();

    $this->artisan('email:paywall-follow-up')->assertSuccessful();

    Mail::assertQueued(PaywallFollowUpEmail::class, fn ($mail) => $mail->hasTo($user->email));
});

test('it still sends to users who connected a bank', function () {
    $user = stuckPaywallUser();
    BankingConnection::factory()->for($user)->create();

    $this->artisan('email:paywall-follow-up')->assertSuccessful();

    Mail::assertQueued(PaywallFollowUpEmail::class, fn ($mail) => $mail->hasTo($user->email));
});

test('it does not send to users who never saw the paywall', function () {
    User::factory()->create(['onboarded_at' => now()->subDay(), 'paywall_seen_at' => null]);

    $this->artisan('email:paywall-follow-up')->assertSuccessful();

    Mail::assertNotQueued(PaywallFollowUpEmail::class);
});

test('it does not send to the shared demo account', function () {
    config(['app.demo.email' => 'demo@whisper.money']);
    stuckPaywallUser()->update(['email' => 'demo@whisper.money']);

    $this->artisan('email:paywall-follow-up')->assertSuccessful();

    Mail::assertNotQueued(PaywallFollowUpEmail::class);
});

test('it does not send to users who opted out of marketing emails', function () {
    $user = stuckPaywallUser();
    UserSetting::factory()->for($user)->create(['notify_marketing' => false]);

    $this->artisan('email:paywall-follow-up')->assertSuccessful();

    Mail::assertNotQueued(PaywallFollowUpEmail::class);
});

test('it does not send to users with a pro plan', function () {
    $user = stuckPaywallUser();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    $this->artisan('email:paywall-follow-up')->assertSuccessful();

    Mail::assertNotQueued(PaywallFollowUpEmail::class);
});

test('it does not send the email twice to the same user', function () {
    $user = stuckPaywallUser();
    UserMailLog::factory()->for($user)->paywallFollowUp()->create();

    $this->artisan('email:paywall-follow-up')->assertSuccessful();

    Mail::assertNotQueued(PaywallFollowUpEmail::class);

    expect(
        UserMailLog::query()
            ->where('user_id', $user->id)
            ->where('email_type', DripEmailType::PaywallFollowUp)
            ->count()
    )->toBe(1);
});

test('it does nothing when drip emails are disabled', function () {
    config(['mail.drip_emails_enabled' => false]);

    stuckPaywallUser();

    $this->artisan('email:paywall-follow-up')->assertSuccessful();

    Mail::assertNotQueued(PaywallFollowUpEmail::class);
});
