<?php

use App\Support\PriceTiers;

it('gives a tier its own prices and its own Stripe lookup keys', function (string $tier, array $expected) {
    expect(PriceTiers::plansFor($tier))->toBe($expected);
})->with([
    'high' => ['high', [
        'monthly' => ['price' => 8.99, 'original_price' => null, 'stripe_lookup_key' => 'whisper_pro_monthly_high'],
        'yearly' => ['price' => 53.94, 'original_price' => 107.88, 'stripe_lookup_key' => 'whisper_pro_yearly_high'],
    ]],
    'low' => ['low', [
        'monthly' => ['price' => 3.99, 'original_price' => null, 'stripe_lookup_key' => 'whisper_pro_monthly'],
        'yearly' => ['price' => 23.88, 'original_price' => 47.88, 'stripe_lookup_key' => 'whisper_pro_yearly'],
    ]],
    'latam' => ['latam', [
        'monthly' => ['price' => 3.99, 'original_price' => null, 'stripe_lookup_key' => 'whisper_pro_latam_monthly'],
        'yearly' => ['price' => 23.88, 'original_price' => 47.88, 'stripe_lookup_key' => 'whisper_pro_latam_yearly'],
    ]],
]);

it('refuses a tier it does not declare', function () {
    PriceTiers::plansFor('hgih');
})->throws(InvalidArgumentException::class);

// Old `low` subscribers keep their bank connections, so latam must never
// share their Stripe prices: entitlements follow the price paid.
it('gives the latam tier Stripe prices of its own', function () {
    $latamKeys = array_column(PriceTiers::plansFor(PriceTiers::LATAM), 'stripe_lookup_key');

    foreach (array_diff(PriceTiers::names(), [PriceTiers::LATAM]) as $tier) {
        expect(array_intersect($latamKeys, array_column(PriceTiers::plansFor($tier), 'stripe_lookup_key')))->toBeEmpty();
    }
});

it('finds the tier a lookup key belongs to', function (string $lookupKey, ?string $tier) {
    expect(PriceTiers::tierOfLookupKey($lookupKey))->toBe($tier);
})->with([
    'high' => ['whisper_pro_yearly_high', 'high'],
    'low' => ['whisper_pro_monthly', 'low'],
    'latam' => ['whisper_pro_latam_yearly', 'latam'],
    'unknown' => ['whisper_pro_lifetime', null],
]);
