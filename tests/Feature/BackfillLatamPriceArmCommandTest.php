<?php

use App\Models\User;
use App\Support\PriceTiers;

beforeEach(function () {
    config(['subscriptions.latam_pricing' => true]);
});

function latamUser(array $attributes = []): User
{
    return User::factory()->create([
        'timezone' => 'America/Bogota',
        'currency_code' => 'COP',
        'price_arm' => null,
        ...$attributes,
    ]);
}

function giveSubscription(User $user, string $status, ?DateTimeInterface $endsAt = null, string $stripeId = 'sub_test'): void
{
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => $stripeId.'_'.$user->id,
        'stripe_status' => $status,
        'stripe_price' => 'price_test123',
        'ends_at' => $endsAt,
    ]);
}

test('moves a non-paying latam user to the latam price', function () {
    $user = latamUser();

    $this->artisan('pricing:backfill-latam-price-arm')
        ->assertSuccessful()
        ->expectsOutputToContain('1 user(s) moved to the latam price.');

    expect($user->fresh()->price_arm)->toBe(PriceTiers::LATAM);
});

test('falls back to the currency when the timezone names no country', function () {
    $byCurrency = latamUser(['timezone' => null, 'currency_code' => 'MXN']);
    $notLatam = latamUser(['timezone' => 'UTC', 'currency_code' => 'EUR']);

    $this->artisan('pricing:backfill-latam-price-arm')
        ->assertSuccessful()
        ->expectsTable(['Region', 'Users'], [['currency: MXN', 1]]);

    expect($byCurrency->fresh()->price_arm)->toBe(PriceTiers::LATAM)
        ->and($notLatam->fresh()->price_arm)->toBeNull();
});

test('leaves users outside latin america alone', function () {
    $spain = latamUser(['timezone' => 'Europe/Madrid', 'currency_code' => 'EUR']);
    $us = latamUser(['timezone' => 'America/New_York', 'currency_code' => 'USD']);
    $usWithLatamCurrency = latamUser(['timezone' => 'America/Chicago', 'currency_code' => 'MXN']);

    $this->artisan('pricing:backfill-latam-price-arm')
        ->assertSuccessful()
        ->expectsOutputToContain('0 user(s) moved');

    expect($spain->fresh()->price_arm)->toBeNull()
        ->and($us->fresh()->price_arm)->toBeNull()
        ->and($usWithLatamCurrency->fresh()->price_arm)->toBeNull();
});

test('never overwrites a price arm that is already set', function () {
    $user = latamUser(['price_arm' => 'standard']);

    $this->artisan('pricing:backfill-latam-price-arm')->assertSuccessful();

    expect($user->fresh()->price_arm)->toBe('standard');
});

test('skips users with a subscription that has not ended', function (string $status, ?string $endsAt) {
    $user = latamUser();
    giveSubscription($user, $status, $endsAt === null ? null : now()->modify($endsAt));

    $this->artisan('pricing:backfill-latam-price-arm')->assertSuccessful();

    expect($user->fresh()->price_arm)->toBeNull();
})->with([
    'active' => ['active', null],
    'trialing' => ['trialing', null],
    'past due' => ['past_due', null],
    'incomplete' => ['incomplete', null],
    'cancelled on grace period' => ['active', '+5 days'],
]);

test('another user\'s subscription does not hold back a free user', function () {
    $subscriber = latamUser();
    giveSubscription($subscriber, 'active', now()->addDays(5));
    $free = latamUser();

    $this->artisan('pricing:backfill-latam-price-arm')->assertSuccessful();

    expect($subscriber->fresh()->price_arm)->toBeNull()
        ->and($free->fresh()->price_arm)->toBe(PriceTiers::LATAM);
});

test('moves a former subscriber whose subscription has ended', function () {
    $user = latamUser();
    giveSubscription($user, 'canceled', now()->subDay());

    $this->artisan('pricing:backfill-latam-price-arm')->assertSuccessful();

    expect($user->fresh()->price_arm)->toBe(PriceTiers::LATAM);
});

test('skips users on a generic trial', function () {
    $onTrial = latamUser(['trial_ends_at' => now()->addWeek()]);
    $trialOver = latamUser(['trial_ends_at' => now()->subWeek()]);

    $this->artisan('pricing:backfill-latam-price-arm')->assertSuccessful();

    expect($onTrial->fresh()->price_arm)->toBeNull()
        ->and($trialOver->fresh()->price_arm)->toBe(PriceTiers::LATAM);
});

test('skips the shared demo and press accounts', function () {
    config(['app.demo.email' => 'demo@example.com', 'app.press.email' => 'press@example.com']);
    $demo = latamUser(['email' => 'demo@example.com']);
    $press = latamUser(['email' => 'press@example.com']);

    $this->artisan('pricing:backfill-latam-price-arm')->assertSuccessful();

    expect($demo->fresh()->price_arm)->toBeNull()
        ->and($press->fresh()->price_arm)->toBeNull();
});

test('skips seeded accounts through their fake subscription', function () {
    $user = latamUser();
    giveSubscription($user, 'active', null, 'sub_e2e');

    $this->artisan('pricing:backfill-latam-price-arm')->assertSuccessful();

    expect($user->fresh()->price_arm)->toBeNull();
});

test('dry run reports the breakdown without writing', function () {
    $colombia = latamUser();
    latamUser(['timezone' => 'America/Mexico_City', 'currency_code' => 'USD']);
    latamUser(['timezone' => 'America/Bogota', 'currency_code' => 'EUR']);

    $this->artisan('pricing:backfill-latam-price-arm', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('DRY RUN')
        ->expectsTable(['Region', 'Users'], [['CO', 2], ['MX', 1]])
        ->expectsOutputToContain('3 user(s) would be moved to the latam price.');

    expect($colombia->fresh()->price_arm)->toBeNull()
        ->and(User::query()->whereNotNull('price_arm')->count())->toBe(0);
});

test('dry run works while latam pricing is off', function () {
    config(['subscriptions.latam_pricing' => false]);
    latamUser();

    $this->artisan('pricing:backfill-latam-price-arm', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('1 user(s) would be moved');
});

test('refuses to write while latam pricing is off', function () {
    config(['subscriptions.latam_pricing' => false]);
    $user = latamUser();

    $this->artisan('pricing:backfill-latam-price-arm')
        ->assertFailed()
        ->expectsOutputToContain('LATAM pricing is off');

    expect($user->fresh()->price_arm)->toBeNull();
});

test('is idempotent', function () {
    $user = latamUser();

    $this->artisan('pricing:backfill-latam-price-arm')->assertSuccessful();
    $this->artisan('pricing:backfill-latam-price-arm')
        ->assertSuccessful()
        ->expectsOutputToContain('0 user(s) moved');

    expect($user->fresh()->price_arm)->toBe(PriceTiers::LATAM);
});
