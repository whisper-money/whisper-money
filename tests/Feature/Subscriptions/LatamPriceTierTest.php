<?php

use App\Models\User;
use App\Services\Subscriptions\SubscribedPlan;
use App\Support\PriceTiers;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Subscription;

beforeEach(function () {
    config(['subscriptions.enabled' => true]);

    // The price-to-lookup-key map Stripe would answer with, so nothing reaches it.
    Cache::put('stripe_lookup_keys_by_price_id', [
        'price_high_yearly' => 'whisper_pro_yearly_high',
        'price_low_monthly' => 'whisper_pro_monthly',
        'price_latam_monthly' => 'whisper_pro_latam_monthly',
        'price_latam_yearly' => 'whisper_pro_latam_yearly',
    ], now()->addDay());
});

function subscriptionPaying(string $stripePrice, string $stripeId = 'sub_live_1'): Subscription
{
    $user = User::factory()->onboarded()->create();

    return $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => $stripeId,
        'stripe_status' => 'active',
        'stripe_price' => $stripePrice,
        'quantity' => 1,
    ]);
}

test('a subscription\'s tier is the one of the price it pays', function (string $stripePrice, ?string $tier) {
    $subscription = subscriptionPaying($stripePrice);
    $plans = app(SubscribedPlan::class);

    expect($plans->tierFor($subscription->user, $subscription))->toBe($tier)
        ->and($plans->isLatam($subscription->user, $subscription))->toBe($tier === PriceTiers::LATAM);
})->with([
    'standard' => ['price_high_yearly', 'high'],
    'pre-experiment low price, which keeps its banks' => ['price_low_monthly', 'low'],
    'latam monthly' => ['price_latam_monthly', 'latam'],
    'latam yearly' => ['price_latam_yearly', 'latam'],
    'a price no tier declares' => ['price_unknown', null],
]);

test('a seeded subscription has no tier, and never reaches Stripe', function () {
    Cache::forget('stripe_lookup_keys_by_price_id');
    $subscription = subscriptionPaying('price_latam_monthly', 'sub_demo_1');

    expect(app(SubscribedPlan::class)->tierFor($subscription->user, $subscription))->toBeNull();
});

test('a latam subscriber stays latam when the latam price stops being offered', function () {
    config(['subscriptions.latam_pricing' => false]);
    $subscription = subscriptionPaying('price_latam_yearly');

    expect(app(SubscribedPlan::class)->isLatam($subscription->user, $subscription))->toBeTrue();
});

test('a user placed in latam at signup is offered the latam tier only while it is offered', function (bool $offered, string $tier) {
    config(['subscriptions.latam_pricing' => $offered]);
    $user = User::factory()->create(['price_arm' => PriceTiers::LATAM]);

    expect($user->offeredPriceTier())->toBe($tier);
})->with([
    'latam pricing on' => [true, 'latam'],
    'latam pricing off' => [false, 'high'],
]);

test('everyone else is offered the high price', function (?string $priceArm) {
    config(['subscriptions.latam_pricing' => true]);
    $user = User::factory()->create(['price_arm' => $priceArm]);

    expect($user->offeredPriceTier())->toBe('high');
})->with([
    'never placed' => [null],
    'an arm left over from the #980 experiment' => ['control'],
]);

test('a latam user is offered the configured plans at the latam prices', function () {
    config(['subscriptions.latam_pricing' => true]);
    $user = User::factory()->create(['price_arm' => PriceTiers::LATAM]);

    $plans = $user->offeredPlans();

    expect($plans['monthly'])
        ->toMatchArray(['price' => 3.99, 'stripe_lookup_key' => 'whisper_pro_latam_monthly', 'billing_period' => 'month'])
        ->and($plans['yearly'])
        ->toMatchArray(['price' => 23.88, 'original_price' => 47.88, 'stripe_lookup_key' => 'whisper_pro_latam_yearly', 'billing_period' => 'year'])
        ->and($plans['yearly']['trial_days'])->toBe(config('subscriptions.plans.yearly.trial_days'));
});

test('a user offered the high price gets the configured plans untouched', function () {
    config(['subscriptions.latam_pricing' => true]);
    $user = User::factory()->create(['price_arm' => null]);

    expect($user->offeredPlans())->toBe(config('subscriptions.plans'));
});
