<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendPromoCodeEmailJob;
use App\Mail\Drip\PromoCodeEmail;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config(['subscriptions.enabled' => true]);
});

test('promo code email is sent to onboarded users with transactions who are not subscribed', function () {
    $user = User::factory()->onboarded()->create();
    Transaction::factory()->for($user)->create();

    SendPromoCodeEmailJob::dispatchSync($user);

    Mail::assertSent(PromoCodeEmail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });

    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::PromoCode->value,
    ]);
});

test('promo code email is not sent to non-onboarded users', function () {
    $user = User::factory()->notOnboarded()->create();
    Transaction::factory()->for($user)->create();

    SendPromoCodeEmailJob::dispatchSync($user);

    Mail::assertNotSent(PromoCodeEmail::class);
});

test('promo code email is not sent to users without transactions', function () {
    $user = User::factory()->onboarded()->create();

    SendPromoCodeEmailJob::dispatchSync($user);

    Mail::assertNotSent(PromoCodeEmail::class);
});

test('promo code email is not sent to subscribed users', function () {
    $user = User::factory()->onboarded()->create();
    Transaction::factory()->for($user)->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    SendPromoCodeEmailJob::dispatchSync($user);

    Mail::assertNotSent(PromoCodeEmail::class);
});

test('promo code email is not sent if already received', function () {
    $user = User::factory()->onboarded()->create();
    Transaction::factory()->for($user)->create();

    UserMailLog::factory()->for($user)->promoCode()->create();

    SendPromoCodeEmailJob::dispatchSync($user);

    Mail::assertNotSent(PromoCodeEmail::class);
});
