<?php

namespace App\Support;

/**
 * The price Whisper Money charges for the paid plan, as complete tiers.
 *
 * A tier welds the displayed price, the struck-through price and the Stripe
 * lookup key together, because the amount actually charged lives in Stripe
 * under that key: a price shown without its matching key is a lie to the user.
 * Both tiers' Stripe prices already exist, so switching between them needs no
 * `stripe:sync-prices` run — only `config:clear`.
 *
 * @api Consumed by config/subscriptions.php, which static analysis does not scan.
 *
 * @phpstan-type TierPlan array{price: float, original_price: float|null, stripe_lookup_key: string}
 */
final class PriceTiers
{
    public const DEFAULT = 'high';

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
    ];

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
        return self::TIERS[strtolower(trim((string) $tier))] ?? self::TIERS[self::DEFAULT];
    }
}
