<?php

use Stripe\PromotionCode;
use Stripe\Service\PromotionCodeService;
use Stripe\StripeClient;

function makeStripePromotionCode(string $id, string $code): PromotionCode
{
    return PromotionCode::constructFrom([
        'id' => $id,
        'code' => $code,
    ]);
}

test('creates requested number of single use promotion codes for default coupon', function () {
    $createdParams = [];

    $promotionCodeService = Mockery::mock(PromotionCodeService::class);
    $promotionCodeService->shouldReceive('create')
        ->times(3)
        ->andReturnUsing(function (array $params) use (&$createdParams): PromotionCode {
            $createdParams[] = $params;
            $index = count($createdParams);

            return makeStripePromotionCode("promo_{$index}", $params['code']);
        });

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->promotionCodes = $promotionCodeService;

    app()->bind(StripeClient::class, fn () => $stripeClient);

    $this->artisan('stripe:generate-promotion-codes', ['count' => 3])
        ->expectsOutputToContain('Generating 3 single-use promotion codes for coupon 0E5fAsXG...')
        ->expectsOutputToContain('Generated 3 promotion codes.')
        ->assertSuccessful();

    expect($createdParams)->toHaveCount(3);

    foreach ($createdParams as $params) {
        expect($params['coupon'])->toBe('0E5fAsXG');
        expect($params['max_redemptions'])->toBe(1);
        expect($params['code'])->toStartWith('WM-');
    }

    expect(collect($createdParams)->pluck('code')->unique())->toHaveCount(3);
});

test('fails when count is not a positive integer', function () {
    $this->artisan('stripe:generate-promotion-codes', ['count' => 0])
        ->expectsOutputToContain('Count must be a positive integer.')
        ->assertFailed();
});
