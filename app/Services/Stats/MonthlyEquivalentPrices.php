<?php

namespace App\Services\Stats;

use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Cashier;

/**
 * Monthly-equivalent amount (in cents) for each active recurring Stripe price id
 * under the pro product, yearly prices divided by 12. Fetched by product so that
 * archived, rotated price ids (Stripe mints a new id and transfers the lookup key
 * on any amount change) still resolve — otherwise subscriptions on an old id would
 * silently contribute 0 to MRR — and so every experiment price tier is covered by
 * one call. Falls back to the current lookup keys when no product is configured.
 * Foreign-currency and one-off prices are skipped. Cached for an hour; returns []
 * (revenue unavailable) if Stripe can't be reached, without caching the failure.
 *
 * Shared by the trial and price experiment funnel collectors so "what does each
 * price cost per month" has a single source of truth.
 */
class MonthlyEquivalentPrices
{
    private const CACHE_KEY = 'experiment_funnel_monthly_equiv';

    /**
     * @return array<string, int>
     */
    public function map(): array
    {
        if (Cache::has(self::CACHE_KEY)) {
            return Cache::get(self::CACHE_KEY);
        }

        $productId = config('subscriptions.products.pro');
        $lookups = array_values(array_filter([
            config('subscriptions.plans.monthly.stripe_lookup_key'),
            config('subscriptions.plans.yearly.stripe_lookup_key'),
        ]));

        if ($productId === null && $lookups === []) {
            return [];
        }

        $params = $productId !== null
            ? ['product' => $productId, 'limit' => 100]
            : ['lookup_keys' => $lookups, 'limit' => 10];

        try {
            $prices = Cashier::stripe()->prices->all($params);
        } catch (\Throwable) {
            return [];
        }

        $currency = strtolower((string) config('cashier.currency', 'eur'));
        $map = [];
        foreach ($prices->data as $price) {
            if ($price->recurring === null) {
                continue;
            }

            if (strtolower((string) ($price->currency ?? $currency)) !== $currency) {
                continue;
            }

            $amount = (int) ($price->unit_amount ?? 0);
            $map[$price->id] = ($price->recurring->interval ?? 'month') === 'year'
                ? (int) round($amount / 12)
                : $amount;
        }

        if ($map !== []) {
            Cache::put(self::CACHE_KEY, $map, now()->addHour());
        }

        return $map;
    }
}
