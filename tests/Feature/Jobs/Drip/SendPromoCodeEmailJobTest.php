<?php

use App\Jobs\Drip\SendPromoCodeEmailJob;
use App\Mail\Drip\PromoCodeEmail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config(['subscriptions.enabled' => true]);
});

test('promo code email is not sent to non-onboarded users', function () {
    $user = User::factory()->notOnboarded()->create();
    Transaction::factory()->for($user)->create();

    SendPromoCodeEmailJob::dispatchSync($user);

    Mail::assertNotQueued(PromoCodeEmail::class);
});

test('promo code email is not sent to users without transactions', function () {
    $user = User::factory()->onboarded()->create();

    SendPromoCodeEmailJob::dispatchSync($user);

    Mail::assertNotQueued(PromoCodeEmail::class);
});

test('promo code email is not sent to users with only onboarding estimates', function () {
    $user = User::factory()->onboarded()->create();
    giveOnboardingEstimates($user, 3);

    SendPromoCodeEmailJob::dispatchSync($user);

    Mail::assertNotQueued(PromoCodeEmail::class);
});

test('promo code email is sent to users with real transactions next to their estimates', function () {
    $user = User::factory()->onboarded()->create();
    giveOnboardingEstimates($user);
    Transaction::factory()->for($user)->create();

    SendPromoCodeEmailJob::dispatchSync($user);

    Mail::assertQueued(PromoCodeEmail::class);
});
