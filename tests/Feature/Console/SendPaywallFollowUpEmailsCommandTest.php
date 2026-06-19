<?php

use App\Enums\DripEmailType;
use App\Mail\Drip\PaywallFollowUpEmail;
use App\Models\BankingConnection;
use App\Models\User;
use App\Models\UserMailLog;
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
    $user = User::factory()->create(['onboarded_at' => now()->subDay()]);
    BankingConnection::factory()->for($user)->create();

    return $user;
}

test('it sends the follow-up email to users onboarded yesterday with a bank connection and no pro plan', function () {
    $user = stuckPaywallUser();

    $this->artisan('email:paywall-follow-up')->assertSuccessful();

    Mail::assertQueued(PaywallFollowUpEmail::class, fn ($mail) => $mail->hasTo($user->email));

    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::PaywallFollowUp->value,
    ]);
});

test('it does not send to users who onboarded today or earlier than yesterday', function () {
    User::factory()->create(['onboarded_at' => now()])
        ->bankingConnections()->save(BankingConnection::factory()->make());

    User::factory()->create(['onboarded_at' => now()->subDays(2)])
        ->bankingConnections()->save(BankingConnection::factory()->make());

    $this->artisan('email:paywall-follow-up')->assertSuccessful();

    Mail::assertNotQueued(PaywallFollowUpEmail::class);
});

test('it does not send to users without a bank connection', function () {
    User::factory()->create(['onboarded_at' => now()->subDay()]);

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
