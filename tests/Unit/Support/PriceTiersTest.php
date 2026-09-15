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
    'case and padding do not lose the flip' => [' LOW ', [
        'monthly' => ['price' => 3.99, 'original_price' => null, 'stripe_lookup_key' => 'whisper_pro_monthly'],
        'yearly' => ['price' => 23.88, 'original_price' => 47.88, 'stripe_lookup_key' => 'whisper_pro_yearly'],
    ]],
]);

// A typo in .env must not take the app down or charge an amount nobody chose.
it('falls back to the default tier for anything it does not know', function (?string $tier) {
    expect(PriceTiers::plansFor($tier))->toBe(PriceTiers::plansFor(PriceTiers::DEFAULT));
})->with([
    'typo' => ['hgih'],
    'empty' => [''],
    'unset' => [null],
]);
