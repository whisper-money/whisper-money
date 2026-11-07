<?php

namespace App\Services\Subscriptions;

use App\Models\User;
use App\Support\PriceTiers;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
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

    /** How long a failed or empty lookup is kept before Stripe is asked again. */
    private const RETRY_AFTER_MINUTES = 5;

    /**
     * @return array{price: float, billing_period: string}|null
     */
    public function for(User $user, Subscription $subscription): ?array
    {
        $lookupKey = $this->lookupKeyFor($user, $subscription);

        return $lookupKey === null ? null : $this->planForLookupKey($lookupKey);
    }

    /**
     * The price tier (`App\Support\PriceTiers`) of the price a subscription
     * pays, or null when it is not a price any tier declares (or a seeded
     * subscription's made-up one). This is what entitlements follow, never
     * the tier the user is offered.
     */
    public function tierFor(User $user, Subscription $subscription): ?string
    {
        $lookupKey = $this->lookupKeyFor($user, $subscription);

        if ($lookupKey === null) {
            return null;
        }

        // A key overridden through the environment is declared by no tier, and
        // belongs to the configured one, the only tier whose keys can be.
        $configuredKeys = array_column(config('subscriptions.plans'), 'stripe_lookup_key');

        return PriceTiers::tierOfLookupKey($lookupKey)
            ?? (in_array($lookupKey, $configuredKeys, true) ? config('subscriptions.price_tier') : null);
    }

    /**
     * Whether a subscription pays the regional latam price.
     */
    public function isLatam(User $user, Subscription $subscription): bool
    {
        return $this->tierFor($user, $subscription) === PriceTiers::LATAM;
    }

    /**
     * Drop the cached price-to-lookup-key map, so a price that was just
     * created in Stripe is recognised straight away instead of after a day.
     */
    public static function forgetLookupKeys(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function lookupKeyFor(User $user, Subscription $subscription): ?string
    {
        // A seeded subscription carries a made-up price and must never reach Stripe.
        if ($user->cannotUseStripe()) {
            return null;
        }

        return $this->lookupKeysByPriceId()[$subscription->stripe_price] ?? null;
    }

    /**
     * @return array{price: float, billing_period: string}|null
     */
    private function planForLookupKey(string $lookupKey): ?array
    {
        foreach ($this->candidatePlans() as $key => $plan) {
            if ($plan['stripe_lookup_key'] === $lookupKey) {
                return [
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
            report($exception);
            $prices = null;
        }

        $map = [];
        foreach ($prices->data ?? [] as $price) {
            $map[$price->id] = $price->lookup_key;
        }

        // A failed or empty answer is only kept briefly: long enough that an
        // outage doesn't make every visit wait on Stripe, short enough that a
        // missing `stripe:sync-prices` run doesn't stick for a day.
        Cache::put(self::CACHE_KEY, $map, $map === [] ? now()->addMinutes(self::RETRY_AFTER_MINUTES) : now()->addDay());

        return $map;
    }
}
