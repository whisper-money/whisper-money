<?php

namespace App\Listeners;

use App\Jobs\Drip\SendTrialEndingEmailJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Warns a trialling user three days before their card is charged for the first
 * time. Stripe fires `customer.subscription.trial_will_end` on that schedule.
 *
 * The first charge after a trial is where our involuntary churn comes from,
 * almost always an insufficient-funds decline, so the email states the date and
 * the exact amount and links to the billing portal.
 */
class SendTrialEndingEmail
{
    private const DEDUPE_TTL_HOURS = 24;

    public function handle(WebhookReceived $event): void
    {
        if (($event->payload['type'] ?? null) !== 'customer.subscription.trial_will_end') {
            return;
        }

        if (! config('mail.drip_emails_enabled')) {
            return;
        }

        $object = $event->payload['data']['object'] ?? [];

        if (($object['cancel_at_period_end'] ?? false) === true) {
            return;
        }

        $customerId = $this->stringOrNull($object['customer'] ?? null);
        $trialEnd = $object['trial_end'] ?? null;
        $price = $object['items']['data'][0]['price'] ?? $object['plan'] ?? [];
        $amount = (int) ($price['unit_amount'] ?? $price['amount'] ?? 0);

        if ($customerId === null || ! is_int($trialEnd) || $amount <= 0) {
            return;
        }

        $user = User::query()->where('stripe_id', $customerId)->first();

        if ($user === null || $this->alreadyProcessed($event->payload)) {
            return;
        }

        SendTrialEndingEmailJob::dispatch(
            $user,
            CarbonImmutable::createFromTimestamp($trialEnd),
            $amount,
            (string) ($price['currency'] ?? 'eur'),
        );
    }

    /**
     * Stripe retries deliveries, so the first one wins. The mail log keyed on
     * the trial end date is the durable guard; this only closes the window
     * where two deliveries are in flight at once.
     *
     * @param  array<string, mixed>  $payload
     */
    private function alreadyProcessed(array $payload): bool
    {
        $id = $this->stringOrNull($payload['id'] ?? null);

        if ($id === null) {
            return false;
        }

        return ! Cache::add('trial-ending:stripe-event:'.$id, true, now()->addHours(self::DEDUPE_TTL_HOURS));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
