<?php

namespace App\Listeners;

use App\Models\FreePlanSurveyResponse;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Subscription;

/**
 * Stamps `converted_at` on the free-plan survey answers of a user who has
 * since started a trial or a paid plan.
 *
 * Listens to WebhookHandled, after Cashier has upserted the local subscription,
 * and acts on the moment a subscription becomes `trialing` or `active`: when it
 * is created that way, or when an update moves it there (a checkout whose first
 * payment needs 3-D Secure is created `incomplete` and only turns `active`
 * later). An update that leaves the status alone, such as a renewal or a card
 * change, is no conversion, so answers given before this column existed by a
 * user who was already subscribed are not stamped with the date of their next
 * renewal. Answers already stamped keep their date.
 */
class MarkFreePlanSurveyResponsesConverted
{
    private const CONVERTED_STATUSES = ['trialing', 'active'];

    public function handle(WebhookHandled $event): void
    {
        $object = $event->payload['data']['object'] ?? [];

        if (! $this->startsSubscription($event->payload)) {
            return;
        }

        $userId = Subscription::query()
            ->where('stripe_id', (string) ($object['id'] ?? ''))
            ->value('user_id');

        if ($userId === null) {
            return;
        }

        FreePlanSurveyResponse::query()
            ->where('user_id', $userId)
            ->whereNull('converted_at')
            ->update(['converted_at' => now()]);
    }

    /**
     * Whether this event is the subscription turning `trialing` or `active`.
     *
     * @param  array<string, mixed>  $payload
     */
    private function startsSubscription(array $payload): bool
    {
        $status = $payload['data']['object']['status'] ?? null;

        if (! in_array($status, self::CONVERTED_STATUSES, true)) {
            return false;
        }

        return match ($payload['type'] ?? null) {
            'customer.subscription.created' => true,
            // Stripe lists the old status only when the update changed it.
            'customer.subscription.updated' => ! in_array(
                $payload['data']['previous_attributes']['status'] ?? $status,
                self::CONVERTED_STATUSES,
                true,
            ),
            default => false,
        };
    }
}
