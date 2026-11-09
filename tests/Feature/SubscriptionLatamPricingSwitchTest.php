<?php

declare(strict_types=1);

use App\Support\PriceTiers;

/**
 * `SUBSCRIPTION_LATAM_PRICING` is read once while the config file is
 * evaluated, so it is exercised by evaluating that file again with the
 * environment set.
 *
 * @param  array<string, string|null>  $environment
 * @return array<string, mixed>
 */
function subscriptionsConfigWith(array $environment): array
{
    $previous = [];

    $set = static function (string $key, ?string $value): void {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    };

    foreach ($environment as $key => $value) {
        $previous[$key] = $_ENV[$key] ?? null;
        $set($key, $value);
    }

    try {
        return require config_path('subscriptions.php');
    } finally {
        foreach ($previous as $key => $value) {
            $set($key, $value);
        }
    }
}

it('does not offer the latam price while the switch is absent', function () {
    expect(subscriptionsConfigWith(['SUBSCRIPTION_LATAM_PRICING' => null])['latam_pricing'])->toBeFalse();
});

it('offers the latam price when the switch is on', function (string $value, bool $expected) {
    expect(subscriptionsConfigWith(['SUBSCRIPTION_LATAM_PRICING' => $value])['latam_pricing'])->toBe($expected);
})->with([
    'true' => ['true', true],
    '1' => ['1', true],
    'false' => ['false', false],
    'blank' => ['', false],
]);

// The old `SUBSCRIPTION_PRICE_TIER` selector is gone: a value left behind in a
// hosting panel must not move anybody off the high price.
it('quotes the high price whatever the retired tier selector says', function () {
    $config = subscriptionsConfigWith(['SUBSCRIPTION_PRICE_TIER' => 'low']);

    expect($config)->not->toHaveKey('price_tier')
        ->and(array_column($config['plans'], 'stripe_lookup_key'))
        ->toBe(array_column(PriceTiers::plansFor(PriceTiers::DEFAULT), 'stripe_lookup_key'))
        ->and(array_column($config['plans'], 'price'))->toBe([8.99, 53.94]);
});
