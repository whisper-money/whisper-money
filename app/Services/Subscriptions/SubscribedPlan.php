<?php

namespace App\Services\Subscriptions;

use App\Models\User;
use App\Support\PriceTiers;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Cashier;
use Throwable;

/**
 * The plan a subscriber is actually paying for, read off the Stripe price on
 * their subscription rather than assumed from the default plan — a monthly
 * subscriber must not be shown the yearly price.
 *
 * The config only knows lookup keys and a subscription only knows a price ID,
 * so the two are joined through one Stripe call for every known lookup key,
 * cached for a day. Every tier is matched, not only the current one: someone
 * who subscribed before a price change still pays the price they signed up at.
 */
class SubscribedPlan
{
    private const CACHE_KEY = 'stripe_lookup_keys_by_price_id';

    /**
     * @return array{key: string, price: float, billing_period: string}|null
     */
    public function for(User $user): ?array
    {
        $priceId = $user->subscription('default')?->stripe_price;

        // A seeded subscription carries a made-up price and must never reach Stripe.
        if ($priceId === null || $user->cannotUseStripe()) {
            return null;
        }

        $lookupKey = $this->lookupKeysByPriceId()[$priceId] ?? null;

        return $lookupKey === null ? null : $this->planForLookupKey($lookupKey);
    }

    /**
     * @return array{key: string, price: float, billing_period: string}|null
     */
    private function planForLookupKey(string $lookupKey): ?array
    {
        foreach ($this->candidatePlans() as $key => $plan) {
            if ($plan['stripe_lookup_key'] === $lookupKey) {
                return [
                    'key' => $key,
                    'price' => (float) $plan['price'],
                    'billing_period' => (string) config("subscriptions.plans.{$key}.billing_period"),
                ];
            }
        }

        return null;
    }

    /**
     * The configured plans first, so a lookup key overridden through the
     * environment is still recognised, then every tier's.
     *
     * @return iterable<string, array{price: float|int, stripe_lookup_key: string}>
     */
    private function candidatePlans(): iterable
    {
        yield from config('subscriptions.plans');

        foreach (PriceTiers::names() as $tier) {
            yield from PriceTiers::plansFor($tier);
        }
    }

    /**
     * @return array<string, string>
     */
    private function lookupKeysByPriceId(): array
    {
        if (($cached = Cache::get(self::CACHE_KEY)) !== null) {
            return $cached;
        }

        $lookupKeys = [];
        foreach ($this->candidatePlans() as $plan) {
            $lookupKeys[] = $plan['stripe_lookup_key'];
        }
        $lookupKeys = array_values(array_unique(array_filter($lookupKeys)));

        try {
            $prices = Cashier::stripe()->prices->all(['lookup_keys' => $lookupKeys, 'limit' => 100]);
        } catch (Throwable $exception) {
            // Not cached: the page falls back to the default plan and the next
            // visit tries again.
            report($exception);

            return [];
        }

        $map = [];
        foreach ($prices->data as $price) {
            $map[$price->id] = $price->lookup_key;
        }

        Cache::put(self::CACHE_KEY, $map, now()->addDay());

        return $map;
    }
}
