<?php

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

    $priceService = Mockery::mock(PriceService::class);
    $priceService->shouldNotReceive('all');
    $priceService->shouldNotReceive('create');

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->prices = $priceService;
    app()->bind(StripeClient::class, fn () => $stripeClient);

    $this->artisan('stripe:sync-prices')
        ->expectsOutputToContain('No stripe_lookup_key defined')
        ->assertSuccessful();
});

test('reports correct counts when all prices are already in sync', function () {
    bindMockStripeClientForSync([
        'whisper_pro_monthly' => makeStripePrice('price_monthly', 399, 'eur', 'month'),
        'whisper_pro_yearly' => makeStripePrice('price_yearly', 2388, 'eur', 'year'),
    ]);

    $this->artisan('stripe:sync-prices')
        ->expectsOutputToContain('0 created, 0 updated, 2 skipped')
        ->assertSuccessful();
});

test('creates prices that do not yet exist in stripe', function () {
    bindMockStripeClientForSync(pricesByLookupKey: [], createdPriceId: 'price_new456');

    $this->artisan('stripe:sync-prices')
        ->expectsOutputToContain('2 created, 0 updated, 0 skipped')
        ->assertSuccessful();
});

test('transfers lookup key when price amount has changed', function () {
    bindMockStripeClientForSync([
        'whisper_pro_monthly' => makeStripePrice('price_monthly_old', 999, 'eur', 'month'),
        'whisper_pro_yearly' => makeStripePrice('price_yearly_old', 9900, 'eur', 'year'),
    ]);

    $this->artisan('stripe:sync-prices')
        ->expectsOutputToContain('0 created, 2 updated, 0 skipped')
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
        ->expectsOutputToContain('2 created, 0 updated, 0 skipped (dry-run)')
        ->assertSuccessful();
});

test('returns success and warns when no plans are configured', function () {
    config(['subscriptions.plans' => []]);

    $this->artisan('stripe:sync-prices')
        ->expectsOutputToContain('No plans found')
        ->assertSuccessful();
});
