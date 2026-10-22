<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Stripe\Collection;
use Stripe\Exception\ApiConnectionException;
use Stripe\Service\PriceService;
use Stripe\StripeClient;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    config(['subscriptions.enabled' => true]);
});

/**
 * Bind a Stripe client whose price listing answers with the given price IDs
 * keyed by lookup key, and count how many times it was asked.
 *
 * @param  array<string, string>  $priceIdsByLookupKey
 */
function bindStripePrices(array $priceIdsByLookupKey, ?Throwable $failure = null): object
{
    $calls = new class
    {
        public int $count = 0;
    };

    $priceService = Mockery::mock(PriceService::class);
    $priceService->shouldReceive('all')->andReturnUsing(function (array $params) use ($priceIdsByLookupKey, $failure, $calls): Collection {
        $calls->count++;

        if ($failure !== null) {
            throw $failure;
        }

        $data = collect($priceIdsByLookupKey)
            ->only($params['lookup_keys'])
            ->map(fn (string $id, string $key) => ['id' => $id, 'object' => 'price', 'lookup_key' => $key])
            ->values()
            ->all();

        return Collection::constructFrom(['data' => $data, 'object' => 'list']);
    });

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->prices = $priceService;
    app()->bind(StripeClient::class, fn () => $stripeClient);

    return $calls;
}

function subscriber(array $attributes, string $stripeId = 'sub_factory_1'): User
{
    $user = User::factory()->onboarded()->create();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => $stripeId,
        'stripe_status' => 'active',
        'stripe_price' => 'price_monthly_high',
        ...$attributes,
    ]);

    return $user;
}

$knownPrices = [
    'whisper_pro_monthly_high' => 'price_monthly_high',
    'whisper_pro_yearly_high' => 'price_yearly_high',
    'whisper_pro_monthly' => 'price_monthly_low',
];

test('a trialing subscriber gets the trial end and the plan they will be charged for', function () use ($knownPrices) {
    bindStripePrices($knownPrices);
    $trialEndsAt = now()->addDays(14)->startOfSecond();
    $user = subscriber(['stripe_status' => 'trialing', 'trial_ends_at' => $trialEndsAt]);

    actingAs($user)->withoutVite()->get(route('settings.billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/billing')
            ->where('subscription.onTrial', true)
            ->where('subscription.trialEndsAt', $trialEndsAt->toIso8601String())
            ->where('subscription.onGracePeriod', false)
            ->where('subscription.endsAt', null)
            ->where('subscription.plan', ['key' => 'monthly', 'price' => 8.99, 'billing_period' => 'month'])
        );
});

test('a trial canceled before it ends is on its grace period', function () use ($knownPrices) {
    bindStripePrices($knownPrices);
    $trialEndsAt = now()->addDays(5)->startOfSecond();
    $user = subscriber([
        'stripe_status' => 'trialing',
        'trial_ends_at' => $trialEndsAt,
        'ends_at' => $trialEndsAt,
    ]);

    actingAs($user)->withoutVite()->get(route('settings.billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('subscription.onTrial', true)
            ->where('subscription.onGracePeriod', true)
            ->where('subscription.endsAt', $trialEndsAt->toIso8601String())
        );
});

test('an active paid subscriber sees the price of their own plan, not the default one', function () use ($knownPrices) {
    bindStripePrices($knownPrices);
    $user = subscriber([]);

    actingAs($user)->withoutVite()->get(route('settings.billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('subscription.onTrial', false)
            ->where('subscription.trialEndsAt', null)
            ->where('subscription.plan.key', 'monthly')
            ->where('subscription.plan.price', 8.99)
        );
});

test('a subscriber from an older price tier sees the price they signed up at', function () use ($knownPrices) {
    bindStripePrices($knownPrices);
    $user = subscriber(['stripe_price' => 'price_monthly_low']);

    actingAs($user)->withoutVite()->get(route('settings.billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('subscription.plan', ['key' => 'monthly', 'price' => 3.99, 'billing_period' => 'month'])
        );
});

test('an unknown price leaves the plan unresolved', function () use ($knownPrices) {
    bindStripePrices($knownPrices);
    $user = subscriber(['stripe_price' => 'price_factory']);

    actingAs($user)->withoutVite()->get(route('settings.billing'))
        ->assertInertia(fn (Assert $page) => $page->where('subscription.plan', null));
});

test('the price lookup is cached across visits', function () use ($knownPrices) {
    $calls = bindStripePrices($knownPrices);
    $user = subscriber([]);

    actingAs($user)->withoutVite()->get(route('settings.billing'))->assertOk();
    actingAs($user)->withoutVite()->get(route('settings.billing'))->assertOk();

    expect($calls->count)->toBe(1);
});

test('a Stripe outage falls back to an unresolved plan and is not cached', function () {
    $calls = bindStripePrices([], new ApiConnectionException('down'));
    $user = subscriber([]);

    actingAs($user)->withoutVite()->get(route('settings.billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('subscription.plan', null));

    expect($calls->count)->toBe(1)
        ->and(Cache::has('stripe_lookup_keys_by_price_id'))->toBeFalse();
});

test('a seeded subscription never reaches Stripe', function () use ($knownPrices) {
    $calls = bindStripePrices($knownPrices);
    $user = subscriber(['stripe_price' => 'price_monthly_high'], stripeId: 'sub_demo_1');

    actingAs($user)->withoutVite()->get(route('settings.billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('subscription.onTrial', false)
            ->where('subscription.plan', null)
        );

    expect($calls->count)->toBe(0);
});

test('a user without a subscription gets no subscription state', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user)->withoutVite()->get(route('settings.billing'))
        ->assertInertia(fn (Assert $page) => $page->where('subscription', null));
});
