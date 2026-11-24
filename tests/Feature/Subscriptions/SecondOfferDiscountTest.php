<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Models\UserLead;
use App\Support\PriceTiers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\SubscriptionBuilder;
use Stripe\Collection;
use Stripe\Exception\InvalidRequestException;
use Stripe\Service\PromotionCodeService;
use Stripe\StripeClient;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.latam_pricing' => true,
    ]);

    // What Stripe would answer, so nothing reaches it.
    Cache::put('stripe_price_id:whisper_pro_monthly_high', 'price_high_monthly', now()->addHour());
    Cache::put('stripe_price_id:whisper_pro_yearly_high', 'price_high_yearly', now()->addHour());
    Cache::put('stripe_price_id:whisper_pro_latam_monthly', 'price_latam_monthly', now()->addHour());
    Cache::put('stripe_price_id:whisper_pro_latam_yearly', 'price_latam_yearly', now()->addHour());
});

/**
 * A free user who was shown the second offer `$shownDaysAgo` days ago (never,
 * when null), whose checkout stops at a subscription builder that records the
 * discount it is given.
 *
 * @return array{0: User, 1: ArrayObject<string, mixed>}
 */
function secondOfferCheckout(?int $shownDaysAgo = 1, ?string $priceArm = null, int $checkouts = 1): array
{
    $user = User::factory()->onboarded()->create([
        'price_arm' => $priceArm,
        'second_offer_shown_at' => $shownDaysAgo === null ? null : now()->subDays($shownDaysAgo),
    ]);

    $discount = new ArrayObject;

    $checkout = Mockery::mock(Checkout::class);
    $checkout->shouldReceive('toResponse')->andReturn(new RedirectResponse('https://stripe.test/session'));

    $builder = Mockery::mock(SubscriptionBuilder::class)->shouldIgnoreMissing();
    $builder->shouldReceive('withCoupon')->andReturnUsing(function (?string $id) use ($discount, &$builder) {
        if ($id === null) {
            unset($discount['coupon']);
        } else {
            $discount['coupon'] = $id;
        }

        return $builder;
    });
    $builder->shouldReceive('withPromotionCode')->andReturnUsing(function (string $id) use ($discount, &$builder) {
        $discount['promotion_code'] = $id;

        return $builder;
    });
    $builder->shouldReceive('allowPromotionCodes')->andReturnUsing(function () use ($discount, &$builder) {
        $discount['allows_promotion_codes'] = true;

        return $builder;
    });
    $builder->shouldReceive('checkout')->times($checkouts)->andReturnUsing(function () use ($discount, $checkout) {
        // A coupon Stripe does not have, as it answers when the command that
        // creates it has not run there.
        if (isset($discount['coupon']) && $discount['coupon'] === 'wm_missing_coupon') {
            throw InvalidRequestException::factory('No such coupon: wm_missing_coupon');
        }

        return $checkout;
    });

    $user = Mockery::mock($user)->makePartial();
    $user->shouldReceive('newSubscription')->once()->andReturn($builder);

    return [$user, $discount];
}

/**
 * @param  array<string, string>  $query
 */
function startCheckout(User $user, array $query): void
{
    test()->withoutMiddleware(HandleInertiaRequests::class)
        ->actingAs($user)
        ->get(route('subscribe.checkout', $query))
        ->assertRedirect();
}

test('a user shown the second offer this week gets its coupon at checkout', function (string $plan) {
    [$user, $discount] = secondOfferCheckout(shownDaysAgo: 6);

    startCheckout($user, ['plan' => $plan, 'source' => 'second_offer']);

    expect($discount->getArrayCopy())->toBe(['coupon' => 'wm_second_offer_30_3m']);
})->with(['monthly', 'yearly']);

test('the coupon id comes from the config', function () {
    config(['subscriptions.second_offer_coupon.id' => 'wm_custom_second_offer']);
    [$user, $discount] = secondOfferCheckout();

    startCheckout($user, ['plan' => 'yearly', 'source' => 'second_offer']);

    expect($discount->getArrayCopy())->toBe(['coupon' => 'wm_custom_second_offer']);
});

test('a checkout the coupon is not meant for is the normal checkout', function (?int $shownDaysAgo, array $query) {
    [$user, $discount] = secondOfferCheckout($shownDaysAgo);

    startCheckout($user, $query);

    expect($discount->getArrayCopy())->toBe(['allows_promotion_codes' => true]);
})->with([
    'shown more than a week ago' => [8, ['plan' => 'yearly', 'source' => 'second_offer']],
    'never shown' => [null, ['plan' => 'yearly', 'source' => 'second_offer']],
    'another upsell point' => [1, ['plan' => 'yearly', 'source' => 'paywall']],
    'no upsell point' => [1, ['plan' => 'yearly']],
]);

test('a former subscriber does not get the coupon', function () {
    [$user, $discount] = secondOfferCheckout();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_former',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_high_yearly',
        'ends_at' => now()->subMonth(),
    ]);

    startCheckout($user, ['plan' => 'yearly', 'source' => 'second_offer']);

    expect($discount->getArrayCopy())->toBe(['allows_promotion_codes' => true]);
});

test('the latam price does not get the coupon', function () {
    [$user, $discount] = secondOfferCheckout(priceArm: PriceTiers::LATAM);

    startCheckout($user, ['plan' => 'yearly', 'source' => 'second_offer', 'tier' => 'latam']);

    expect($discount->getArrayCopy())->toBe(['allows_promotion_codes' => true]);
});

test('a user offered latam who buys the standard price gets the coupon', function () {
    [$user, $discount] = secondOfferCheckout(priceArm: PriceTiers::LATAM);

    startCheckout($user, ['plan' => 'yearly', 'source' => 'second_offer']);

    expect($discount->getArrayCopy())->toBe(['coupon' => 'wm_second_offer_30_3m']);
});

test('a coupon missing from Stripe never costs the sale', function () {
    config(['subscriptions.second_offer_coupon.id' => 'wm_missing_coupon']);
    [$user, $discount] = secondOfferCheckout(checkouts: 2);

    startCheckout($user, ['plan' => 'yearly', 'source' => 'second_offer']);

    expect($discount->getArrayCopy())->toBe(['allows_promotion_codes' => true]);
});

test('no coupon is applied once its id is cleared', function () {
    config(['subscriptions.second_offer_coupon.id' => '']);
    [$user, $discount] = secondOfferCheckout();

    startCheckout($user, ['plan' => 'yearly', 'source' => 'second_offer']);

    expect($discount->getArrayCopy())->toBe(['allows_promotion_codes' => true]);
});

describe('a lead with a promotion code', function () {
    beforeEach(function () {
        $promotionCodes = Mockery::mock(PromotionCodeService::class);
        $promotionCodes->shouldReceive('all')->andReturn(Collection::constructFrom([
            'data' => [['id' => 'promo_lead_yearly']],
        ]));

        $stripeClient = Mockery::mock(StripeClient::class);
        $stripeClient->promotionCodes = $promotionCodes;
        app()->bind(StripeClient::class, fn () => $stripeClient);
    });

    test('gets the coupon instead of the code, never both', function () {
        [$user, $discount] = secondOfferCheckout();
        UserLead::factory()->create(['email' => $user->email, 'promo_code_yearly' => 'LEAD-YEARLY']);

        startCheckout($user, ['plan' => 'yearly', 'source' => 'second_offer']);

        expect($discount->getArrayCopy())->toBe(['coupon' => 'wm_second_offer_30_3m']);
    });

    test('keeps the code when the coupon is not meant for them', function () {
        [$user, $discount] = secondOfferCheckout(shownDaysAgo: 30);
        UserLead::factory()->create(['email' => $user->email, 'promo_code_yearly' => 'LEAD-YEARLY']);

        startCheckout($user, ['plan' => 'yearly', 'source' => 'second_offer']);

        expect($discount->getArrayCopy())->toBe(['promotion_code' => 'promo_lead_yearly']);
    });
});

test('the saving the dialog quotes is shared from the config', function () {
    $user = User::factory()->onboarded()->create(['paywall_seen_at' => now()->subWeek()]);

    $this->actingAs($user)->withoutVite()
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pricing.secondOfferDiscount', ['percentOff' => 30, 'months' => 3]));

    config(['subscriptions.second_offer_coupon.id' => '']);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('pricing.secondOfferDiscount', null));
});
