<?php

namespace App\Listeners;

use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Events\WebhookHandled;
use Stripe\Exception\ApiErrorException;
use Stripe\Subscription as StripeSubscription;

/**
 * Keeps the local pause window in step with Stripe's `pause_collection`, so a
 * pause set or lifted somewhere else (Stripe's dashboard, the automatic
 * resume when `resumes_at` comes) is honoured here too. Cashier syncs nothing
 * of it: to Cashier a paused subscription is simply `active`.
 *
 * The event only says that the subscription changed. Stripe does not deliver
 * events in order, and an older one landing after a pause was set would lift
 * it here while Stripe keeps voiding invoices, so the pause is read from the
 * subscription as Stripe has it now.
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

        // Nothing to sync when neither side has a pause: most events are about
        // something else, and they are not worth a call to Stripe.
        $involvesPause = ! empty($object['pause_collection']) || $subscription?->pause_starts_at !== null;

        if ($subscription === null || ! $involvesPause || $subscription->owner->cannotUseStripe()) {
            return;
        }

        try {
            $stripeSubscription = $subscription->asStripeSubscription();
        } catch (ApiErrorException $exception) {
            // Left as it is rather than guessed: the next event syncs it.
            report($exception);

            return;
        }

        $subscription->forceFill($this->window($subscription, $stripeSubscription));

        if ($subscription->isDirty()) {
            $subscription->save();
        }
    }

    /**
     * @return array{pause_starts_at: ?CarbonImmutable, pause_resumes_at: ?CarbonImmutable}
     */
    private function window(Subscription $subscription, StripeSubscription $stripeSubscription): array
    {
        $pause = $stripeSubscription->pause_collection;

        if (empty($pause)) {
            return ['pause_starts_at' => null, 'pause_resumes_at' => null];
        }

        $stripeResumesAt = is_int($pause->resumes_at ?? null) ? CarbonImmutable::createFromTimestamp($pause->resumes_at) : null;

        // Ours: Stripe holds the moment just before the renewal we stored.
        if (
            $stripeResumesAt !== null
            && $subscription->pause_resumes_at !== null
            && Subscription::stripeResumesAt($subscription->pause_resumes_at)->equalTo($stripeResumesAt)
        ) {
            return [
                'pause_starts_at' => $subscription->pause_starts_at?->toImmutable(),
                'pause_resumes_at' => $subscription->pause_resumes_at->toImmutable(),
            ];
        }

        return [
            'pause_starts_at' => $this->startOf($subscription, $stripeSubscription, $stripeResumesAt),
            'pause_resumes_at' => $stripeResumesAt,
        ];
    }

    /**
     * Where a pause set or moved outside the app starts: the start already
     * stored, when it belongs to a pause that is still set; otherwise the end
     * of the period running now, or now when the pause ends before that.
     */
    private function startOf(Subscription $subscription, StripeSubscription $stripeSubscription, ?CarbonImmutable $resumesAt): CarbonImmutable
    {
        if ($subscription->hasPause()) {
            return $subscription->pause_starts_at->toImmutable();
        }

        $periodEnd = $stripeSubscription->items->data[0]->current_period_end ?? null;
        $startsAt = is_int($periodEnd) ? CarbonImmutable::createFromTimestamp($periodEnd) : CarbonImmutable::now();

        return $resumesAt !== null && $startsAt->greaterThanOrEqualTo($resumesAt) ? CarbonImmutable::now() : $startsAt;
    }
}
