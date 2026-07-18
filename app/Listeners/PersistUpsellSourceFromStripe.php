<?php

namespace App\Listeners;

use App\Enums\UpsellSource;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Subscription;

/**
 * Copies the upsell attribution set at checkout (Stripe subscription metadata)
 * onto the local subscription row so revenue can be measured per upsell point.
 *
 * Listens to WebhookHandled (fired after Cashier has already upserted the local
 * subscription) and only fills the column while it's empty, so a later
 * subscription.updated event never overwrites the original attribution.
 */
class PersistUpsellSourceFromStripe
{
    public function handle(WebhookHandled $event): void
    {
        $type = $event->payload['type'] ?? null;

        if (! is_string($type) || ! str_starts_with($type, 'customer.subscription.')) {
            return;
        }

        $object = $event->payload['data']['object'] ?? [];
        $stripeId = $object['id'] ?? null;
        $source = UpsellSource::tryFrom((string) ($object['metadata']['upsell_source'] ?? ''));

        if (! is_string($stripeId) || $stripeId === '' || $source === null) {
            return;
        }

        Subscription::query()
            ->where('stripe_id', $stripeId)
            ->whereNull('upsell_source')
            ->update(['upsell_source' => $source->value]);
    }
}
