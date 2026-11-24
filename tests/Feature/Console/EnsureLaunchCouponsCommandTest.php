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
 * recording every coupon the command creates. An existing coupon is an id, or
 * an id with the terms Stripe holds for it.
 *
 * @param  array<int|string, string|array<string, mixed>>  $existingCoupons
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
        if (isset($existingCoupons[$id])) {
            return Coupon::constructFrom(['id' => $id, ...$existingCoupons[$id]]);
        }

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

test('creates the retention and second offer coupons from the config', function () {
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
        'wm_second_offer_30_3m' => [
            'id' => 'wm_second_offer_30_3m',
            'name' => 'WM Second Offer – 30% off for 3 months',
            'percent_off' => 30,
            'duration' => 'repeating',
            'duration_in_months' => 3,
        ],
    ]);
});

const THIRTY_OFF_FOR_THREE_MONTHS = ['percent_off' => 30, 'duration' => 'repeating', 'duration_in_months' => 3];

test('leaves the coupons alone once they exist', function () {
    $created = fakeStripeCoupons([
        'wm_founder_forever',
        'wm_earlybird_monthly',
        'wm_earlybird_yearly',
        'wm_retention_30_3m' => THIRTY_OFF_FOR_THREE_MONTHS,
        'wm_second_offer_30_3m' => THIRTY_OFF_FOR_THREE_MONTHS,
    ]);

    $this->artisan('stripe:ensure-launch-coupons')->assertSuccessful();

    expect($created->getArrayCopy())->toBe([]);
});

test('fails when a quoted coupon exists in Stripe with other terms', function () {
    fakeStripeCoupons([
        'wm_founder_forever',
        'wm_earlybird_monthly',
        'wm_earlybird_yearly',
        'wm_retention_30_3m' => THIRTY_OFF_FOR_THREE_MONTHS,
        'wm_second_offer_30_3m' => ['percent_off' => 20, 'duration' => 'repeating', 'duration_in_months' => 3],
    ]);

    $this->artisan('stripe:ensure-launch-coupons')->assertFailed();
});

test('skips a coupon whose id is cleared in config', function () {
    config(['subscriptions.second_offer_coupon.id' => '']);
    $created = fakeStripeCoupons(['wm_founder_forever', 'wm_earlybird_monthly', 'wm_earlybird_yearly', 'wm_retention_30_3m' => THIRTY_OFF_FOR_THREE_MONTHS]);

    $this->artisan('stripe:ensure-launch-coupons')->assertSuccessful();

    expect($created->getArrayCopy())->toBe([]);
});
