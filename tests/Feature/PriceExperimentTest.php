<?php

use App\Features\PriceExperiment;
use App\Features\SubscriptionExperiment;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Subscriptions\ExperimentOffer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\SubscriptionBuilder;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.price_experiment.started_at' => '2026-06-01',
        'subscriptions.price_experiment.force_variant' => null,
        'subscriptions.price_experiment.variants.high' => [
            'monthly' => ['price' => 8.99, 'original_price' => null, 'lookup' => 'whisper_pro_monthly_v9'],
            'yearly' => ['price' => 53.94, 'original_price' => 107.88, 'lookup' => 'whisper_pro_yearly_v9'],
        ],
        'subscriptions.plans.monthly.price' => 3.99,
        'subscriptions.plans.monthly.stripe_lookup_key' => 'whisper_pro_monthly',
        'subscriptions.plans.yearly.price' => 23.88,
        'subscriptions.plans.yearly.stripe_lookup_key' => 'whisper_pro_yearly',
    ]);
});

it('keeps users who registered before the experiment on the control price', function () {
    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-05-20')]);

    expect(app(ExperimentOffer::class)->priceVariantFor($user))->toBe(PriceExperiment::LEGACY);
});

it('treats a null (guest) scope as legacy', function () {
    expect((new PriceExperiment)->resolve(null))->toBe(PriceExperiment::LEGACY);
});

it('treats everyone as legacy while the experiment is off', function () {
    config(['subscriptions.price_experiment.started_at' => null]);

    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    expect(app(ExperimentOffer::class)->priceVariantFor($user))->toBe(PriceExperiment::LEGACY);
});

it('pins every user to the forced winner price', function () {
    config(['subscriptions.price_experiment.force_variant' => PriceExperiment::HIGH]);
    $offer = app(ExperimentOffer::class);

    $legacy = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-05-01')]);
    $fresh = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    expect($offer->priceVariantFor($legacy))->toBe(PriceExperiment::HIGH)
        ->and($offer->priceVariantFor($fresh))->toBe(PriceExperiment::HIGH);
});

it('ignores an invalid forced variant', function () {
    config(['subscriptions.price_experiment.force_variant' => 'bogus']);

    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-05-01')]);

    expect(app(ExperimentOffer::class)->priceVariantFor($user))->toBe(PriceExperiment::LEGACY);
});

it('splits post-start users across control and high and stays stable per user', function () {
    $offer = app(ExperimentOffer::class);
    $variants = [];

    for ($i = 0; $i < 40; $i++) {
        $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);
        $assigned = $offer->priceVariantFor($user);
        $variants[] = $assigned;

        Feature::flushCache();
        expect($offer->priceVariantFor($user))->toBe($assigned);
    }

    expect(array_unique($variants))->toEqualCanonicalizing([
        PriceExperiment::CONTROL,
        PriceExperiment::HIGH,
    ]);
});

it('assigns the price independently of the trial experiment (salted hash)', function () {
    // For users the trial experiment buckets into pay_now, the price experiment
    // must still produce BOTH price arms — otherwise the two are collinear.
    $priceArmsWithinOneTrialArm = [];

    for ($i = 0; $i < 200 && count(array_unique($priceArmsWithinOneTrialArm)) < 2; $i++) {
        $id = (string) Str::uuid();

        if (SubscriptionExperiment::bucket($id) === SubscriptionExperiment::PAY_NOW) {
            $priceArmsWithinOneTrialArm[] = PriceExperiment::bucket($id);
        }
    }

    expect(array_unique($priceArmsWithinOneTrialArm))->toEqualCanonicalizing([
        PriceExperiment::CONTROL,
        PriceExperiment::HIGH,
    ]);
});

it('applies the variant price and lookup key for a high-price user', function () {
    $offer = app(ExperimentOffer::class);
    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);
    Feature::for($user)->activate(PriceExperiment::class, PriceExperiment::HIGH);

    $plans = $offer->pricingPlansFor($user);

    expect($plans['monthly']['price'])->toBe(8.99)
        ->and($plans['monthly']['stripe_lookup_key'])->toBe('whisper_pro_monthly_v9')
        ->and($plans['yearly']['price'])->toBe(53.94)
        ->and($plans['yearly']['original_price'])->toBe(107.88)
        ->and($offer->lookupKeyFor($user, 'monthly'))->toBe('whisper_pro_monthly_v9')
        ->and($offer->lookupKeyFor($user, 'yearly'))->toBe('whisper_pro_yearly_v9');
});

it('leaves control-price users on the config prices', function () {
    $offer = app(ExperimentOffer::class);
    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);
    Feature::for($user)->activate(PriceExperiment::class, PriceExperiment::CONTROL);

    $plans = $offer->pricingPlansFor($user);

    expect($plans['monthly']['price'])->toBe(3.99)
        ->and($plans['monthly']['stripe_lookup_key'])->toBe('whisper_pro_monthly')
        ->and($offer->lookupKeyFor($user, 'monthly'))->toBe('whisper_pro_monthly');
});

it('shows the assigned variant price on the paywall', function () {
    $user = User::factory()->onboarded()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);
    Feature::for($user)->activate(PriceExperiment::class, PriceExperiment::HIGH);

    $this->actingAs($user)
        ->get(route('subscribe'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/paywall')
            ->where('pricing.plans.monthly.price', 8.99)
            ->where('pricing.plans.yearly.price', 53.94));
});

it('charges the assigned variant price id at checkout, not a client-supplied one', function () {
    config(['subscriptions.price_experiment.force_variant' => PriceExperiment::HIGH]);
    Cache::put('stripe_price_id:whisper_pro_monthly_v9', 'price_high_monthly', now()->addHour());

    $checkout = Mockery::mock(Checkout::class);
    $checkout->shouldReceive('toResponse')->andReturn(new RedirectResponse('https://stripe.test/session'));

    $builder = Mockery::mock(SubscriptionBuilder::class)->shouldIgnoreMissing();
    $builder->shouldReceive('checkout')->once()->andReturn($checkout);

    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('hasVerifiedEmail')->andReturn(true);
    $user->shouldReceive('hasProPlan')->andReturn(false);
    $user->shouldReceive('newSubscription')
        ->once()
        ->with('default', 'price_high_monthly')
        ->andReturn($builder);

    $this->withoutMiddleware(HandleInertiaRequests::class);
    $this->actingAs($user);

    $this->get(route('subscribe.checkout', ['plan' => 'monthly']))->assertRedirect();
});
