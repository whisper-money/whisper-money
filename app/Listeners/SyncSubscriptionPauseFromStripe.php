<?php

namespace App\Listeners;

use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * Keeps the local pause window in step with Stripe's `pause_collection`, so a
 * pause set or lifted somewhere else (Stripe's dashboard, the automatic
 * resume when `resumes_at` comes) is honoured here too. Cashier syncs nothing
 * of it: to Cashier a paused subscription is simply `active`.
 *
 * The start of a pause is not something Stripe records. One set from the app
 * keeps the start the app gave it; one first seen here starts at the end of
 * the current period, the one already paid for, as the app's own pauses do.
 */
class SyncSubscriptionPauseFromStripe
{
    private const EVENTS = ['customer.subscription.created', 'customer.subscription.updated'];

    public function handle(WebhookHandled $event): void
    {
        if (! in_array($event->payload['type'] ?? null, self::EVENTS, true)) {
            return;
        }

        $object = $event->payload['data']['object'] ?? [];
        $stripeId = $object['id'] ?? null;
        $subscription = is_string($stripeId) && $stripeId !== ''
            ? Subscription::query()->where('stripe_id', $stripeId)->first()
            : null;

        if ($subscription === null) {
            return;
        }

        $pause = $object['pause_collection'] ?? null;

        $subscription->forceFill(is_array($pause) ? $this->window($subscription, $object, $pause) : [
            'pause_starts_at' => null,
            'pause_resumes_at' => null,
        ]);

        if ($subscription->isDirty()) {
            $subscription->save();
        }
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $pause
     * @return array{pause_starts_at: CarbonImmutable, pause_resumes_at: ?CarbonImmutable}
     */
    private function window(Subscription $subscription, array $object, array $pause): array
    {
        $resumesAt = is_int($pause['resumes_at'] ?? null) ? CarbonImmutable::createFromTimestamp($pause['resumes_at']) : null;
        $periodEnd = $object['items']['data'][0]['current_period_end'] ?? $object['current_period_end'] ?? null;
        $startsAt = $subscription->pause_starts_at?->toImmutable()
            ?? (is_int($periodEnd) ? CarbonImmutable::createFromTimestamp($periodEnd) : CarbonImmutable::now());

        // A pause set to end before the paid period does runs from now.
        if ($resumesAt !== null && $startsAt->greaterThanOrEqualTo($resumesAt)) {
            $startsAt = CarbonImmutable::now();
        }

        return ['pause_starts_at' => $startsAt, 'pause_resumes_at' => $resumesAt];
    }
}
