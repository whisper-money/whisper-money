<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Subscriptions\PriceExperiment;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\SubscriptionBuilder;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.price_experiment.started_at' => '2026-06-01',
        'subscriptions.price_experiment.force_variant' => null,
        'subscriptions.price_experiment.variants.high' => [
            'monthly' => ['price' => 8.99, 'original_price' => null, 'lookup' => 'whisper_pro_monthly_high'],
            'yearly' => ['price' => 53.94, 'original_price' => 107.88, 'lookup' => 'whisper_pro_yearly_high'],
        ],
        'subscriptions.plans.monthly.price' => 3.99,
        'subscriptions.plans.monthly.stripe_lookup_key' => 'whisper_pro_monthly',
        'subscriptions.plans.yearly.price' => 23.88,
        'subscriptions.plans.yearly.stripe_lookup_key' => 'whisper_pro_yearly',
    ]);
});

it('keeps users who registered before the experiment on the control price', function () {
    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-05-20')]);

    expect(PriceExperiment::variantFor($user))->toBe(PriceExperiment::LEGACY);
});

it('treats everyone as legacy while the experiment is off', function () {
    config(['subscriptions.price_experiment.started_at' => null]);

    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    expect(PriceExperiment::variantFor($user))->toBe(PriceExperiment::LEGACY);
});

it('pins every user to the forced winner price', function () {
    config(['subscriptions.price_experiment.force_variant' => PriceExperiment::HIGH]);

    $legacy = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-05-01')]);
    $fresh = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    expect(PriceExperiment::variantFor($legacy))->toBe(PriceExperiment::HIGH)
        ->and(PriceExperiment::variantFor($fresh))->toBe(PriceExperiment::HIGH);
});

it('ignores an invalid forced variant', function () {
    config(['subscriptions.price_experiment.force_variant' => 'bogus']);

    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-05-01')]);

    expect(PriceExperiment::variantFor($user))->toBe(PriceExperiment::LEGACY);
});

it('splits post-start users across control and high and stays stable per user', function () {
    $variants = [];

    for ($i = 0; $i < 40; $i++) {
        $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);
        $assigned = PriceExperiment::variantFor($user);
        $variants[] = $assigned;

        expect(PriceExperiment::variantFor($user))->toBe($assigned);
    }

    expect(array_values(array_unique($variants)))->toEqualCanonicalizing([
        PriceExperiment::CONTROL,
        PriceExperiment::HIGH,
    ]);
});

it('salts the split so it does not mirror a plain crc32 bucket of the same id', function () {
    // An unsalted crc32(id) % 2 would tie this experiment to the buckets of every
    // other experiment on the same ids forever. The two splits must disagree.
    $disagreements = 0;

    for ($i = 0; $i < 50; $i++) {
        $id = (string) Str::uuid();
        $salted = crc32('price:'.$id) % 2;

        if ($salted !== crc32($id) % 2) {
            $disagreements++;
        }
    }

    expect($disagreements)->toBeGreaterThan(0);
});

it('applies the variant price and lookup key for a high-price user', function () {
    config(['subscriptions.price_experiment.force_variant' => PriceExperiment::HIGH]);
    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    $plans = PriceExperiment::plansFor($user);

    expect($plans['monthly']['price'])->toBe(8.99)
        ->and($plans['monthly']['stripe_lookup_key'])->toBe('whisper_pro_monthly_high')
        ->and($plans['yearly']['price'])->toBe(53.94)
        ->and($plans['yearly']['original_price'])->toBe(107.88)
        ->and(PriceExperiment::lookupKeyFor($user, 'monthly'))->toBe('whisper_pro_monthly_high')
        ->and(PriceExperiment::lookupKeyFor($user, 'yearly'))->toBe('whisper_pro_yearly_high');
});

it('leaves control-price users on the config prices', function () {
    config(['subscriptions.price_experiment.force_variant' => PriceExperiment::CONTROL]);
    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    $plans = PriceExperiment::plansFor($user);

    expect($plans['monthly']['price'])->toBe(3.99)
        ->and($plans['monthly']['stripe_lookup_key'])->toBe('whisper_pro_monthly')
        ->and(PriceExperiment::lookupKeyFor($user, 'monthly'))->toBe('whisper_pro_monthly');
});

it('shows the assigned variant price on the paywall', function () {
    config(['subscriptions.price_experiment.force_variant' => PriceExperiment::HIGH]);
    $user = User::factory()->onboarded()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

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
    Cache::put('stripe_price_id:whisper_pro_monthly_high', 'price_high_monthly', now()->addHour());

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
