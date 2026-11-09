<?php

use Illuminate\Support\Facades\Cache;
use Stripe\Collection;
use Stripe\Price;
use Stripe\Service\PriceService;
use Stripe\StripeClient;

function makeStripePrice(string $id, int $unitAmount, string $currency, string $interval): Price
{
    return Price::constructFrom([
        'id' => $id,
        'unit_amount' => $unitAmount,
        'currency' => $currency,
        'recurring' => ['interval' => $interval],
    ]);
}

function makeEmptyPriceCollection(): Collection
{
    return Collection::constructFrom(['data' => [], 'object' => 'list']);
}

function makePriceCollection(Price $price): Collection
{
    return Collection::constructFrom(['data' => [$price->toArray()], 'object' => 'list']);
}

/**
 * The regional latam prices, already in Stripe at the tier's amounts: the
 * command syncs them alongside the configured plans whatever tier is set.
 *
 * @return array<string, Price>
 */
function latamPricesInSync(): array
{
    return [
        'whisper_pro_latam_monthly' => makeStripePrice('price_latam_monthly', 399, 'eur', 'month'),
        'whisper_pro_latam_yearly' => makeStripePrice('price_latam_yearly', 2388, 'eur', 'year'),
    ];
}

function bindMockStripeClientForSync(array $pricesByLookupKey = [], ?string $createdPriceId = 'price_new123'): void
{
    $priceService = Mockery::mock(PriceService::class);

    $priceService->shouldReceive('all')
        ->andReturnUsing(function (array $params) use ($pricesByLookupKey): Collection {
            $lookupKey = $params['lookup_keys'][0] ?? null;
            $price = $pricesByLookupKey[$lookupKey] ?? null;

            return $price ? makePriceCollection($price) : makeEmptyPriceCollection();
        });

    $priceService->shouldReceive('create')
        ->andReturnUsing(function () use ($createdPriceId): Price {
            return Price::constructFrom(['id' => $createdPriceId]);
        });

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->prices = $priceService;

    app()->bind(StripeClient::class, fn () => $stripeClient);
}

beforeEach(function () {
    config([
        'subscriptions.plans' => [
            'monthly' => [
                'name' => 'Monthly',
                'price' => 3.99,
                'billing_period' => 'month',
                'stripe_lookup_key' => 'whisper_pro_monthly',
                'features' => [],
            ],
            'yearly' => [
                'name' => 'Yearly',
                'price' => 23.88,
                'billing_period' => 'year',
                'stripe_lookup_key' => 'whisper_pro_yearly',
                'features' => [],
            ],
        ],
        'subscriptions.products.pro' => 'prod_test123',
        'cashier.currency' => 'eur',
    ]);
});

test('skips plans without stripe_lookup_key', function () {
    config([
        'subscriptions.plans' => [
            'monthly' => [
                'name' => 'Monthly',
                'price' => 3.99,
                'billing_period' => 'month',
                'features' => [],
                // no stripe_lookup_key
            ],
        ],
    ]);

    bindMockStripeClientForSync(latamPricesInSync());

    $this->artisan('stripe:sync-prices')
        ->expectsOutputToContain('No stripe_lookup_key defined')
        ->expectsOutputToContain('0 created, 0 updated, 2 skipped')
        ->assertSuccessful();
});

test('reports correct counts when all prices are already in sync', function () {
    bindMockStripeClientForSync([
        'whisper_pro_monthly' => makeStripePrice('price_monthly', 399, 'eur', 'month'),
        'whisper_pro_yearly' => makeStripePrice('price_yearly', 2388, 'eur', 'year'),
        ...latamPricesInSync(),
    ]);

    $this->artisan('stripe:sync-prices')
        ->expectsOutputToContain('0 created, 0 updated, 4 skipped')
        ->assertSuccessful();
});

test('creates prices that do not yet exist in stripe', function () {
    bindMockStripeClientForSync(pricesByLookupKey: latamPricesInSync(), createdPriceId: 'price_new456');

    $this->artisan('stripe:sync-prices')
        ->expectsOutputToContain('2 created, 0 updated, 2 skipped')
        ->assertSuccessful();
});

test('transfers lookup key when price amount has changed', function () {
    bindMockStripeClientForSync([
        'whisper_pro_monthly' => makeStripePrice('price_monthly_old', 999, 'eur', 'month'),
        'whisper_pro_yearly' => makeStripePrice('price_yearly_old', 9900, 'eur', 'year'),
        ...latamPricesInSync(),
    ]);

    $this->artisan('stripe:sync-prices')
        ->expectsOutputToContain('0 created, 2 updated, 2 skipped')
        ->assertSuccessful();
});

test('dry run outputs preview without creating prices', function () {
    $priceService = Mockery::mock(PriceService::class);
    $priceService->shouldReceive('all')->andReturn(makeEmptyPriceCollection());
    $priceService->shouldNotReceive('create');

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->prices = $priceService;
    app()->bind(StripeClient::class, fn () => $stripeClient);

    $this->artisan('stripe:sync-prices', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->expectsOutputToContain('4 created, 0 updated, 0 skipped (dry-run)')
        ->assertSuccessful();
});

test('returns success and warns when no plans are configured', function () {
    config(['subscriptions.plans' => []]);

    $this->artisan('stripe:sync-prices')
        ->expectsOutputToContain('No plans found')
        ->assertSuccessful();
});

test('creates the latam prices alongside the default ones', function () {
    $priceService = Mockery::mock(PriceService::class);
    $priceService->shouldReceive('all')->andReturnUsing(fn (array $params): Collection => match ($params['lookup_keys'][0]) {
        'whisper_pro_monthly' => makePriceCollection(makeStripePrice('price_monthly', 399, 'eur', 'month')),
        'whisper_pro_yearly' => makePriceCollection(makeStripePrice('price_yearly', 2388, 'eur', 'year')),
        default => makeEmptyPriceCollection(),
    });
    $priceService->shouldReceive('create')->once()->with(Mockery::on(fn (array $params): bool => $params['lookup_key'] === 'whisper_pro_latam_monthly'
        && $params['unit_amount'] === 399
        && $params['recurring'] === ['interval' => 'month']
        && $params['transfer_lookup_key'] === false))->andReturn(Price::constructFrom(['id' => 'price_latam_monthly']));
    $priceService->shouldReceive('create')->once()->with(Mockery::on(fn (array $params): bool => $params['lookup_key'] === 'whisper_pro_latam_yearly'
        && $params['unit_amount'] === 2388
        && $params['recurring'] === ['interval' => 'year']
        && $params['transfer_lookup_key'] === false))->andReturn(Price::constructFrom(['id' => 'price_latam_yearly']));

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->prices = $priceService;
    app()->bind(StripeClient::class, fn () => $stripeClient);

    $this->artisan('stripe:sync-prices')
        ->expectsOutputToContain('Monthly (LATAM)')
        ->expectsOutputToContain('2 created, 0 updated, 2 skipped')
        ->assertSuccessful();
});

test('does not sync the latam prices twice when they are the configured ones', function () {
    config([
        'subscriptions.plans.monthly.stripe_lookup_key' => 'whisper_pro_latam_monthly',
        'subscriptions.plans.yearly.stripe_lookup_key' => 'whisper_pro_latam_yearly',
    ]);

    bindMockStripeClientForSync(latamPricesInSync());

    $this->artisan('stripe:sync-prices')
        ->expectsOutputToContain('0 created, 0 updated, 2 skipped')
        ->assertSuccessful();
});

test('forgets the cached price map once it creates a price, so the new one is recognised at once', function () {
    Cache::put('stripe_lookup_keys_by_price_id', ['price_stale' => 'whisper_pro_monthly'], now()->addDay());

    bindMockStripeClientForSync(latamPricesInSync());

    $this->artisan('stripe:sync-prices')->assertSuccessful();

    expect(Cache::has('stripe_lookup_keys_by_price_id'))->toBeFalse();
});

test('keeps the cached price map when nothing changed in stripe', function () {
    Cache::put('stripe_lookup_keys_by_price_id', ['price_monthly' => 'whisper_pro_monthly'], now()->addDay());

    bindMockStripeClientForSync([
        'whisper_pro_monthly' => makeStripePrice('price_monthly', 399, 'eur', 'month'),
        'whisper_pro_yearly' => makeStripePrice('price_yearly', 2388, 'eur', 'year'),
        ...latamPricesInSync(),
    ]);

    $this->artisan('stripe:sync-prices')->assertSuccessful();

    expect(Cache::has('stripe_lookup_keys_by_price_id'))->toBeTrue();
});
