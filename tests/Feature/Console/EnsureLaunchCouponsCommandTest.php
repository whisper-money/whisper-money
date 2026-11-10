<?php

use Stripe\Collection;
use Stripe\Coupon;
use Stripe\Exception\InvalidRequestException;
use Stripe\Price;
use Stripe\Service\CouponService;
use Stripe\Service\PriceService;
use Stripe\StripeClient;

/**
 * A Stripe client with the two plan prices and the coupons that already exist,
 * recording every coupon the command creates.
 *
 * @param  list<string>  $existingCoupons
 */
function fakeStripeCoupons(array $existingCoupons): ArrayObject
{
    $created = new ArrayObject;

    $prices = Mockery::mock(PriceService::class);
    $prices->shouldReceive('all')->andReturnUsing(fn (array $params) => Collection::constructFrom([
        'data' => [['id' => 'price_'.$params['lookup_keys'][0]]],
    ]));
    $prices->shouldReceive('retrieve')->andReturnUsing(fn (string $id) => Price::constructFrom(['id' => $id, 'product' => 'prod_pro']));

    $coupons = Mockery::mock(CouponService::class);
    $coupons->shouldReceive('retrieve')->andReturnUsing(function (string $id) use ($existingCoupons): Coupon {
        if (! in_array($id, $existingCoupons, true)) {
            throw InvalidRequestException::factory('No such coupon');
        }

        return Coupon::constructFrom(['id' => $id]);
    });
    $coupons->shouldReceive('create')->andReturnUsing(function (array $payload) use ($created): Coupon {
        $created[$payload['id']] = $payload;

        return Coupon::constructFrom($payload);
    });

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->prices = $prices;
    $stripeClient->coupons = $coupons;
    app()->bind(StripeClient::class, fn () => $stripeClient);

    return $created;
}

test('creates the cancel flow retention coupon from the config', function () {
    $created = fakeStripeCoupons(['wm_founder_forever', 'wm_earlybird_monthly', 'wm_earlybird_yearly']);

    $this->artisan('stripe:ensure-launch-coupons')->assertSuccessful();

    expect($created->getArrayCopy())->toBe([
        'wm_retention_30_3m' => [
            'id' => 'wm_retention_30_3m',
            'name' => 'WM Retention – 30% off for 3 months',
            'percent_off' => 30,
            'duration' => 'repeating',
            'duration_in_months' => 3,
        ],
    ]);
});

test('leaves the retention coupon alone once it exists', function () {
    $created = fakeStripeCoupons(['wm_founder_forever', 'wm_earlybird_monthly', 'wm_earlybird_yearly', 'wm_retention_30_3m']);

    $this->artisan('stripe:ensure-launch-coupons')->assertSuccessful();

    expect($created->getArrayCopy())->toBe([]);
});
