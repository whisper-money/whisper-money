<?php

namespace App\Services\Subscriptions;

use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Cashier;

/**
 * The Stripe price ID behind a lookup key, which is all the config knows a
 * price by. Cached for an hour under `stripe_price_id:{key}`, the key the
 * update-email command reads too.
 */
class StripePriceIds
{
    /**
     * Aborts with a 500 for a key that has no price in Stripe: charging
     * something else instead is never the right fallback.
     */
    public function forLookupKey(string $lookupKey): string
    {
        return Cache::remember(
            "stripe_price_id:{$lookupKey}",
            now()->addHour(),
            function () use ($lookupKey): string {
                $prices = Cashier::stripe()->prices->all([
                    'lookup_keys' => [$lookupKey],
                    'limit' => 1,
                ]);

                if (empty($prices->data)) {
                    abort(500, "Stripe price not found for lookup key '{$lookupKey}'. Run `php artisan stripe:sync-prices`.");
                }

                return $prices->data[0]->id;
            }
        );
    }
}
