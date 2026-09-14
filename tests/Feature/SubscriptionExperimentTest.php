<?php

use App\Features\SubscriptionExperiment;
use App\Models\User;
use App\Services\Subscriptions\ExperimentOffer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\SubscriptionBuilder;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.experiment.started_at' => '2026-06-01',
        'subscriptions.experiment.refund_window_days' => 3,
        'subscriptions.experiment.variants' => [
            'baseline' => [],
            'short' => ['trial_days' => ['monthly' => 3, 'yearly' => 7]],
            'upfront' => ['trial_days' => ['monthly' => 0, 'yearly' => 0]],
        ],
        'subscriptions.plans.monthly.trial_days' => 15,
        'subscriptions.plans.yearly.trial_days' => 15,
    ]);
});

it('keeps users who registered before the experiment as legacy', function () {
    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-05-20')]);

    expect(app(ExperimentOffer::class)->variantFor($user))->toBe(SubscriptionExperiment::LEGACY);
});

it('treats a null (guest) scope as legacy', function () {
    expect((new SubscriptionExperiment)->resolve(null))->toBe(SubscriptionExperiment::LEGACY);
});

it('treats everyone as legacy while the experiment is off', function () {
    config(['subscriptions.experiment.started_at' => null]);

    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    expect(app(ExperimentOffer::class)->variantFor($user))->toBe(SubscriptionExperiment::LEGACY);
});

it('treats everyone as legacy, and stores nothing, while no variant is declared', function () {
    config(['subscriptions.experiment.variants' => []]);

    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    expect(app(ExperimentOffer::class)->variantFor($user))->toBe(SubscriptionExperiment::LEGACY);

    // A dormant instrument must not litter the features table with a row per user.
    expect(DB::table('features')->where('name', SubscriptionExperiment::class)->count())->toBe(0);
});

it('pins every user to the forced winner variant', function () {
    config(['subscriptions.experiment.force_variant' => 'upfront']);
    $offer = app(ExperimentOffer::class);

    $legacy = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-05-01')]);
    $fresh = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    expect($offer->variantFor($legacy))->toBe('upfront')
        ->and($offer->variantFor($fresh))->toBe('upfront')
        ->and($offer->trialDaysFor($fresh, 'monthly'))->toBe(0);
});

it('ignores an invalid forced variant', function () {
    config(['subscriptions.experiment.force_variant' => 'bogus']);

    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-05-01')]);

    expect(app(ExperimentOffer::class)->variantFor($user))->toBe(SubscriptionExperiment::LEGACY);
});

it('splits post-start users across every declared variant and stays stable per user', function () {
    $offer = app(ExperimentOffer::class);
    $variants = [];

    for ($i = 0; $i < 60; $i++) {
        $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);
        $assigned = $offer->variantFor($user);
        $variants[] = $assigned;

        Feature::flushCache();
        expect($offer->variantFor($user))->toBe($assigned);
    }

    expect(array_values(array_unique($variants)))
        ->toEqualCanonicalizing(['baseline', 'short', 'upfront']);
});

it('applies the trial days that match each variant, falling back to the plan default', function () {
    $offer = app(ExperimentOffer::class);
    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    Feature::for($user)->activate(SubscriptionExperiment::class, 'baseline');
    expect($offer->trialDaysFor($user, 'monthly'))->toBe(15)
        ->and($offer->trialDaysFor($user, 'yearly'))->toBe(15);

    Feature::for($user)->activate(SubscriptionExperiment::class, 'short');
    expect($offer->trialDaysFor($user, 'monthly'))->toBe(3)
        ->and($offer->trialDaysFor($user, 'yearly'))->toBe(7);

    Feature::for($user)->activate(SubscriptionExperiment::class, 'upfront');
    expect($offer->trialDaysFor($user, 'monthly'))->toBe(0)
        ->and($offer->trialDaysFor($user, 'yearly'))->toBe(0);
});

it('applies the assigned variant trial at checkout', function () {
    config(['subscriptions.plans.monthly.stripe_lookup_key' => 'test_monthly_lookup']);
    Cache::put('stripe_price_id:test_monthly_lookup', 'price_test_monthly', now()->addHour());

    $user = User::factory()->onboarded()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('allowPromotionCodes')->once()->andReturnSelf();
    $builder->shouldReceive('trialDays')->once()->with(3)->andReturnSelf();
    $checkout = Mockery::mock(Checkout::class);
    $checkout->shouldReceive('toResponse')->andReturn(new RedirectResponse('https://stripe.test/session'));
    $builder->shouldReceive('checkout')->once()->andReturn($checkout);

    $user = Mockery::mock($user)->makePartial();
    $user->shouldReceive('newSubscription')->once()->with('default', 'price_test_monthly')->andReturn($builder);

    // Assign on the partial mock, not the model it wraps: Pennant keys a scope by
    // class name as well as id, and the mock's class is its own.
    Feature::for($user)->activate(SubscriptionExperiment::class, 'short');

    $this->actingAs($user)
        ->get(route('subscribe.checkout', ['plan' => 'monthly']))
        ->assertRedirect();
});
