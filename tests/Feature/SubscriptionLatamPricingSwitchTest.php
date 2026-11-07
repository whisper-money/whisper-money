<?php

declare(strict_types=1);

/**
 * `SUBSCRIPTION_LATAM_PRICING` and `SUBSCRIPTION_PRICE_TIER` are read once
 * while the config file is evaluated, so they are exercised by evaluating that
 * file again with the environment set.
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

it('names the configured tier, never latam', function (?string $value, string $expected) {
    $config = subscriptionsConfigWith(['SUBSCRIPTION_PRICE_TIER' => $value]);

    expect($config['price_tier'])->toBe($expected)
        ->and($config['plans']['monthly']['stripe_lookup_key'])->not->toContain('latam');
})->with([
    'absent' => [null, 'high'],
    'low' => ['low', 'low'],
    'a typo' => ['hgih', 'high'],
    // The regional price is offered on top of the configured one, never
    // switched to for everybody.
    'latam' => ['latam', 'high'],
]);
