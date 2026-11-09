<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The price Whisper Money charges for the paid plan, as complete tiers.
 *
 * A tier welds the displayed price, the struck-through price and the Stripe
 * lookup key together, because the amount actually charged lives in Stripe
 * under that key: a price shown without its matching key is a lie to the user.
 *
 * `high` is the price everybody is quoted and charged (`DEFAULT`). `latam` is
 * the regional price offered on top of it (see `App\Support\PricingRegion`
 * and `subscriptions.latam_pricing`). `low` is no longer offered to anyone:
 * it is kept only so the subscriptions still paying it are recognised, and it
 * stays apart from `latam`'s prices because what a subscriber can use follows
 * the price they pay and `low` subscribers keep their bank connections.
 *
 * @api Consumed by config/subscriptions.php, which static analysis does not scan.
 *
 * @phpstan-type TierPlan array{price: float, original_price: float|null, stripe_lookup_key: string}
 */
final class PriceTiers
{
    public const DEFAULT = 'high';

    public const LATAM = 'latam';

    /**
     * @var array<string, array<string, TierPlan>>
     */
    private const TIERS = [
        // The price the experiment in #980 settled on, and the one everybody is quoted.
        'high' => [
            'monthly' => ['price' => 8.99, 'original_price' => null, 'stripe_lookup_key' => 'whisper_pro_monthly_high'],
            'yearly' => ['price' => 53.94, 'original_price' => 107.88, 'stripe_lookup_key' => 'whisper_pro_yearly_high'],
        ],
        // The pre-experiment price. Legacy: never offered any more, declared only
        // so the subscriptions still paying it are recognised.
        'low' => [
            'monthly' => ['price' => 3.99, 'original_price' => null, 'stripe_lookup_key' => 'whisper_pro_monthly'],
            'yearly' => ['price' => 23.88, 'original_price' => 47.88, 'stripe_lookup_key' => 'whisper_pro_yearly'],
        ],
        // The regional price for Latin America, which leaves out bank
        // connections through the aggregator.
        self::LATAM => [
            'monthly' => ['price' => 3.99, 'original_price' => null, 'stripe_lookup_key' => 'whisper_pro_latam_monthly'],
            'yearly' => ['price' => 23.88, 'original_price' => 47.88, 'stripe_lookup_key' => 'whisper_pro_latam_yearly'],
        ],
    ];

    /**
     * Every tier's name, for matching a subscription signed up at any of them.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::TIERS);
    }

    /**
     * The per-plan prices for a declared tier.
     *
     * @return array<string, TierPlan>
     */
    public static function plansFor(string $tier): array
    {
        return self::TIERS[$tier] ?? throw new InvalidArgumentException("Unknown price tier [{$tier}].");
    }

    /**
     * The configured plans (`subscriptions.plans`, quoted at the default tier)
     * quoted at another tier: each plan's name, billing period, trial and
     * features stay, while its price, struck-through price and lookup key come
     * from the tier.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function configuredPlansFor(string $tier): array
    {
        /** @var array<string, array<string, mixed>> $plans */
        $plans = config('subscriptions.plans', []);

        if ($tier === self::DEFAULT) {
            return $plans;
        }

        foreach (self::plansFor($tier) as $key => $tierPlan) {
            if (array_key_exists($key, $plans)) {
                $plans[$key] = [...$plans[$key], ...$tierPlan];
            }
        }

        return $plans;
    }

    /**
     * The tier a Stripe lookup key belongs to, or null for one no tier declares.
     */
    public static function tierOfLookupKey(string $lookupKey): ?string
    {
        foreach (self::TIERS as $name => $plans) {
            if (in_array($lookupKey, array_column($plans, 'stripe_lookup_key'), true)) {
                return $name;
            }
        }

        return null;
    }
}
