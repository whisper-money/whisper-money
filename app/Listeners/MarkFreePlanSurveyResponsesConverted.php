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
 * and acts on any subscription event whose status is `trialing` or `active`:
 * a checkout whose first payment needs 3-D Secure is created `incomplete` and
 * only turns `active` in a later `customer.subscription.updated`. Answers
 * already stamped keep their date, so renewals change nothing, and the survey
 * only takes answers from users without a plan, so every answer still open
 * here was given before this subscription started.
 */
class MarkFreePlanSurveyResponsesConverted
{
    private const CONVERTED_STATUSES = ['trialing', 'active'];

    public function handle(WebhookHandled $event): void
    {
        $type = (string) ($event->payload['type'] ?? '');
        $object = $event->payload['data']['object'] ?? [];

        if (! in_array($type, ['customer.subscription.created', 'customer.subscription.updated'], true)
            || ! in_array($object['status'] ?? null, self::CONVERTED_STATUSES, true)) {
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
}
