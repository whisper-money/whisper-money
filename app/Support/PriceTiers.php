<?php

namespace App\Support;

/**
 * The price Whisper Money charges for the paid plan, as complete tiers.
 *
 * A tier welds the displayed price, the struck-through price and the Stripe
 * lookup key together, because the amount actually charged lives in Stripe
 * under that key: a price shown without its matching key is a lie to the user.
 * The `high` and `low` Stripe prices already exist, so switching between them
 * needs no `stripe:sync-prices` run — only `config:clear`.
 *
 * `latam` is not a tier to switch the whole app to: it is the regional price
 * offered on top of the configured one (see `App\Support\PricingRegion` and
 * `subscriptions.latam_pricing`), and it has Stripe prices of its own rather
 * than reusing `low`'s, because what a subscriber can use follows the price
 * they pay and `low` subscribers keep their bank connections.
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
        // The price the experiment in #980 settled on, and the current one.
        'high' => [
            'monthly' => ['price' => 8.99, 'original_price' => null, 'stripe_lookup_key' => 'whisper_pro_monthly_high'],
            'yearly' => ['price' => 53.94, 'original_price' => 107.88, 'stripe_lookup_key' => 'whisper_pro_yearly_high'],
        ],
        // The pre-experiment price, kept switchable so it can be rolled back to
        // without a deploy.
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
     * The per-plan prices for a tier. An unknown name falls back to the default
     * tier rather than throwing: a typo in `.env` must not take the app down or
     * charge an amount nobody chose, and the current price is the safe landing
     * spot.
     *
     * @return array<string, TierPlan>
     */
    public static function plansFor(?string $tier): array
    {
        return self::TIERS[self::normalize($tier)];
    }

    /**
     * A tier's name as declared, or the default tier's for anything unknown,
     * with the same forgiveness as `plansFor()`.
     */
    public static function normalize(?string $tier): string
    {
        $name = strtolower(trim((string) $tier));

        return array_key_exists($name, self::TIERS) ? $name : self::DEFAULT;
    }

    /**
     * The configured plans (`subscriptions.plans`) quoted at a tier: each
     * plan's name, billing period, trial and features stay, while its price,
     * struck-through price and lookup key come from the tier. The configured
     * tier itself returns the plans untouched, so a lookup key overridden
     * through the environment still wins there.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function configuredPlansFor(string $tier): array
    {
        /** @var array<string, array<string, mixed>> $plans */
        $plans = config('subscriptions.plans', []);
        $tier = self::normalize($tier);

        if ($tier === config('subscriptions.price_tier')) {
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
