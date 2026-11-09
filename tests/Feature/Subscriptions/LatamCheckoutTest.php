<?php

use App\Actions\Subscription\SwapLatamToStandard;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Models\UserLead;
use App\Services\Subscriptions\StripePriceIds;
use App\Services\Subscriptions\SubscribedPlan;
use App\Support\PriceTiers;
use App\Support\PricingRegion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Payment;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\SubscriptionBuilder;
use Stripe\PaymentIntent;

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
    Cache::put('stripe_lookup_keys_by_price_id', [
        'price_high_monthly' => 'whisper_pro_monthly_high',
        'price_high_yearly' => 'whisper_pro_yearly_high',
        'price_latam_monthly' => 'whisper_pro_latam_monthly',
        'price_latam_yearly' => 'whisper_pro_latam_yearly',
    ], now()->addDay());
});

/**
 * A real user (so `offeredPriceTier()` runs for real) whose checkout stops at
 * the subscription builder, expected to be handed `$priceId`.
 */
function userCheckingOut(?string $priceArm, ?string $priceId): User
{
    $user = Mockery::mock(User::factory()->onboarded()->create(['price_arm' => $priceArm]))->makePartial();

    if ($priceId === null) {
        $user->shouldNotReceive('newSubscription');

        return $user;
    }

    $checkout = Mockery::mock(Checkout::class);
    $checkout->shouldReceive('toResponse')->andReturn(new RedirectResponse('https://stripe.test/session'));

    $builder = Mockery::mock(SubscriptionBuilder::class)->shouldIgnoreMissing();
    $builder->shouldReceive('checkout')->once()->andReturn($checkout);

    $user->shouldReceive('newSubscription')->once()->with('default', $priceId)->andReturn($builder);

    return $user;
}

test('a user offered latam checks out at the latam price when they ask for it', function (string $plan, string $priceId) {
    $this->withoutMiddleware(HandleInertiaRequests::class)
        ->actingAs(userCheckingOut(PriceTiers::LATAM, $priceId))
        ->get(route('subscribe.checkout', ['plan' => $plan, 'tier' => 'latam']))
        ->assertRedirect();
})->with([
    'monthly' => ['monthly', 'price_latam_monthly'],
    'yearly' => ['yearly', 'price_latam_yearly'],
]);

test('a latam checkout tags the subscription with its tier, a standard one does not', function (?string $tier, ?array $metadata) {
    $user = userCheckingOut(PriceTiers::LATAM, null);

    $checkout = Mockery::mock(Checkout::class);
    $checkout->shouldReceive('toResponse')->andReturn(new RedirectResponse('https://stripe.test/session'));
    $builder = Mockery::mock(SubscriptionBuilder::class)->shouldIgnoreMissing();
    $builder->shouldReceive('checkout')->once()->andReturn($checkout);

    if ($metadata === null) {
        $builder->shouldNotReceive('withMetadata');
    } else {
        $builder->shouldReceive('withMetadata')->once()->with($metadata)->andReturnSelf();
    }

    $user->shouldReceive('newSubscription')->once()->andReturn($builder);

    $this->withoutMiddleware(HandleInertiaRequests::class)
        ->actingAs($user)
        ->get(route('subscribe.checkout', array_filter(['plan' => 'monthly', 'tier' => $tier])))
        ->assertRedirect();
})->with([
    'latam' => ['latam', ['price_tier' => 'latam']],
    'standard' => [null, null],
]);

test('a user offered latam can still buy the standard price', function () {
    $this->withoutMiddleware(HandleInertiaRequests::class)
        ->actingAs(userCheckingOut(PriceTiers::LATAM, 'price_high_yearly'))
        ->get(route('subscribe.checkout', ['plan' => 'yearly']))
        ->assertRedirect();
});

test('the latam price is refused to anyone it is not offered to', function (?string $priceArm, bool $latamPricing) {
    config(['subscriptions.latam_pricing' => $latamPricing]);

    $this->withoutMiddleware(HandleInertiaRequests::class)
        ->actingAs(userCheckingOut($priceArm, null))
        ->get(route('subscribe.checkout', ['plan' => 'yearly', 'tier' => 'latam']))
        ->assertForbidden();
})->with([
    'a standard user, switch on' => [null, true],
    'a latam user, switch off' => [PriceTiers::LATAM, false],
]);

test('with the switch off everyone checks out at the standard price', function () {
    config(['subscriptions.latam_pricing' => false]);

    $this->withoutMiddleware(HandleInertiaRequests::class)
        ->actingAs(userCheckingOut(PriceTiers::LATAM, 'price_high_monthly'))
        ->get(route('subscribe.checkout', ['plan' => 'monthly']))
        ->assertRedirect();
});

test('a latam checkout does not apply the lead promotion code issued for the standard price', function () {
    $user = userCheckingOut(PriceTiers::LATAM, null);
    UserLead::query()->create(['email' => $user->email, 'promo_code_yearly' => 'LEAD50']);

    $checkout = Mockery::mock(Checkout::class);
    $checkout->shouldReceive('toResponse')->andReturn(new RedirectResponse('https://stripe.test/session'));
    $builder = Mockery::mock(SubscriptionBuilder::class)->shouldIgnoreMissing();
    $builder->shouldNotReceive('withPromotionCode');
    $builder->shouldReceive('allowPromotionCodes')->once()->andReturnSelf();
    $builder->shouldReceive('checkout')->once()->andReturn($checkout);
    $user->shouldReceive('newSubscription')->once()->with('default', 'price_latam_yearly')->andReturn($builder);

    $this->withoutMiddleware(HandleInertiaRequests::class)
        ->actingAs($user)
        ->get(route('subscribe.checkout', ['plan' => 'yearly', 'tier' => 'latam']))
        ->assertRedirect();
});

function subscriberPaying(string $stripePrice, array $attributes = []): User
{
    $user = User::factory()->onboarded()->create();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_live_'.fake()->unique()->numerify('######'),
        'stripe_status' => 'active',
        'stripe_price' => $stripePrice,
        'quantity' => 1,
        ...$attributes,
    ]);

    return $user;
}

/**
 * The swap as far as Stripe: `handle()` is stubbed, so the test sees which
 * standard plan the controller picked without a request leaving.
 */
function expectSwapTo(?string $billingPeriod): void
{
    $swap = Mockery::mock(SwapLatamToStandard::class, [app(SubscribedPlan::class), app(StripePriceIds::class)])->makePartial();

    if ($billingPeriod === null) {
        $swap->shouldNotReceive('handle');
    } else {
        $swap->shouldReceive('handle')->once()->with(
            Mockery::type(Subscription::class),
            Mockery::on(fn (array $plan) => $plan['billing_period'] === $billingPeriod),
        );
    }

    app()->instance(SwapLatamToStandard::class, $swap);
}

test('a latam subscriber moves to the standard plan of the same period', function (string $stripePrice, string $billingPeriod, bool $latamPricing) {
    // The switch only governs offering latam: someone paying it can always leave.
    config(['subscriptions.latam_pricing' => $latamPricing]);
    expectSwapTo($billingPeriod);

    $this->actingAs(subscriberPaying($stripePrice))
        ->post(route('settings.billing.swap-to-standard'))
        ->assertRedirect(route('settings.billing'))
        ->assertSessionHas('success');
})->with([
    'monthly, switch on' => ['price_latam_monthly', 'month', true],
    'yearly, switch on' => ['price_latam_yearly', 'year', true],
    'yearly, switch off' => ['price_latam_yearly', 'year', false],
]);

test('a swap whose card needs confirming goes to the payment page and back to billing', function () {
    $swap = Mockery::mock(SwapLatamToStandard::class, [app(SubscribedPlan::class), app(StripePriceIds::class)])->makePartial();
    $swap->shouldReceive('handle')->once()->andThrow(
        IncompletePayment::requiresAction(new Payment(PaymentIntent::constructFrom(['id' => 'pi_qa_3ds']))),
    );
    app()->instance(SwapLatamToStandard::class, $swap);

    $this->actingAs(subscriberPaying('price_latam_yearly'))
        ->post(route('settings.billing.swap-to-standard'))
        ->assertRedirect(route('cashier.payment', ['pi_qa_3ds', 'redirect' => route('settings.billing')]));
});

test('the swap is refused to anyone not on latam', function (string $stripePrice, array $attributes) {
    expectSwapTo(null);

    $this->actingAs(subscriberPaying($stripePrice, $attributes))
        ->post(route('settings.billing.swap-to-standard'))
        ->assertForbidden();
})->with([
    'a standard subscriber' => ['price_high_yearly', []],
    'a cancelled latam subscriber still in their period' => ['price_latam_yearly', ['ends_at' => now()->addMonth()]],
    'a latam subscriber whose payment failed' => ['price_latam_yearly', ['stripe_status' => 'incomplete']],
]);

test('the swap is refused to a user with no subscription', function () {
    expectSwapTo(null);

    $this->actingAs(User::factory()->onboarded()->create(['price_arm' => PriceTiers::LATAM]))
        ->post(route('settings.billing.swap-to-standard'))
        ->assertForbidden();
});

test('the swap charges the standard price behind the plan lookup key', function () {
    $user = subscriberPaying('price_latam_yearly');
    $subscription = Mockery::mock(Subscription::class);
    $subscription->shouldReceive('swapAndInvoice')->once()->with('price_high_yearly');

    app(SwapLatamToStandard::class)->handle($subscription, config('subscriptions.plans.yearly'));
});

test('billing offers the standard plan only to a latam subscriber', function (string $stripePrice, ?array $upgrade) {
    $this->actingAs(subscriberPaying($stripePrice))
        ->get(route('settings.billing'))
        ->assertInertia(fn ($page) => $page->where('subscription.standardUpgrade', $upgrade));
})->with([
    'latam yearly' => ['price_latam_yearly', ['price' => 53.94, 'billing_period' => 'year']],
    'latam monthly' => ['price_latam_monthly', ['price' => 8.99, 'billing_period' => 'month']],
    'standard' => ['price_high_yearly', null],
]);

test('nothing about latam is shared while the switch is off', function () {
    config(['subscriptions.latam_pricing' => false]);

    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->missing('pricing.latam')
            ->missing('pricing.offeredTier'));

    $this->actingAs(User::factory()->onboarded()->create(['price_arm' => PriceTiers::LATAM]))
        ->get(route('subscribe'))
        ->assertInertia(fn ($page) => $page
            ->missing('pricing.latam')
            ->missing('pricing.offeredTier'));
});

test('an anonymous visitor gets the latam plans and the timezones that place them', function () {
    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('pricing.latam.plans.monthly.price', 3.99)
            ->where('pricing.latam.plans.monthly.stripe_lookup_key', 'whisper_pro_latam_monthly')
            ->where('pricing.latam.plans.yearly.price', 23.88)
            ->where('pricing.latam.plans.yearly.features', fn ($features) => ! collect($features)->contains('Connect bank accounts'))
            ->where('pricing.plans.yearly.features', fn ($features) => collect($features)->contains('Connect bank accounts'))
            ->where('pricing.latam.timezones', PricingRegion::latamTimezones())
            ->where('pricing.offeredTier', null));
});

test('a signed-in user gets the tier they were assigned, not a timezone list', function (?string $priceArm, string $tier) {
    $this->actingAs(User::factory()->onboarded()->create(['price_arm' => $priceArm]))
        ->get(route('subscribe'))
        ->assertInertia(fn ($page) => $page
            ->where('pricing.offeredTier', $tier)
            ->where('pricing.latam.timezones', null));
})->with([
    'latam' => [PriceTiers::LATAM, 'latam'],
    'standard' => [null, 'high'],
]);
