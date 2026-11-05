<?php

namespace App\Listeners;

use App\Enums\UpsellSource;
use App\Support\SubscriptionPlans;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Subscription;

/**
 * Copies the attribution set at checkout (Stripe subscription metadata) onto
 * the local subscription row, so revenue can be measured per upsell point and
 * per plan the picker preselected:
 *
 * - `upsell_source`: the upsell point the checkout started from;
 * - `preselected_plan`: the plan selected when the picker was shown, which the
 *   `SUBSCRIPTIONS_DEFAULT_PLAN` switch (or an experiment arm) decides.
 *
 * Listens to WebhookHandled (fired after Cashier has already upserted the local
 * subscription) and only fills each column while it's empty, so a later
 * subscription.updated event never overwrites the original attribution.
 */
class PersistCheckoutAttributionFromStripe
{
    public function handle(WebhookHandled $event): void
    {
        $type = $event->payload['type'] ?? null;

        if (! is_string($type) || ! str_starts_with($type, 'customer.subscription.')) {
            return;
        }

        $object = $event->payload['data']['object'] ?? [];
        $stripeId = $object['id'] ?? null;

        if (! is_string($stripeId) || $stripeId === '') {
            return;
        }

        $metadata = $object['metadata'] ?? [];

        $this->fillWhileEmpty($stripeId, 'upsell_source', UpsellSource::tryFrom((string) ($metadata['upsell_source'] ?? ''))?->value);
        $preselectedPlan = (string) ($metadata['preselected_plan'] ?? '');
        $this->fillWhileEmpty($stripeId, 'preselected_plan', SubscriptionPlans::isKnown($preselectedPlan) ? $preselectedPlan : null);
    }

    private function fillWhileEmpty(string $stripeId, string $column, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        Subscription::query()
            ->where('stripe_id', $stripeId)
            ->whereNull($column)
            ->update([$column => $value]);
    }
}
