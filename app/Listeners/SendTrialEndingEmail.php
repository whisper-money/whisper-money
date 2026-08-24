<?php

namespace App\Listeners;

use App\Jobs\Drip\SendTrialEndingEmailJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Events\WebhookReceived;
use Throwable;

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

        $job = $this->buildJob($event->payload['data']['object'] ?? []);

        if ($job === null) {
            return;
        }

        $claim = $this->dedupeKey($event->payload);

        if ($claim !== null && ! Cache::add($claim, true, now()->addHours(self::DEDUPE_TTL_HOURS))) {
            return;
        }

        // Dispatched through the bus rather than the job's own static helper so
        // a queue failure surfaces here instead of inside PendingDispatch's
        // destructor, where the claim below could not be handed back.
        try {
            Bus::dispatch($job);
        } catch (Throwable $exception) {
            $this->release($claim);

            throw $exception;
        }
    }

    /**
     * Turn the subscription object into the email to send, or null when this
     * subscription is not one to warn about: already set to cancel, missing the
     * fields we quote to the user, or not a customer we know.
     *
     * @param  array<string, mixed>  $object
     */
    private function buildJob(array $object): ?SendTrialEndingEmailJob
    {
        if (($object['cancel_at_period_end'] ?? false) === true) {
            return null;
        }

        $customerId = $this->stringOrNull($object['customer'] ?? null);
        $trialEnd = $object['trial_end'] ?? null;
        $price = $object['items']['data'][0]['price'] ?? $object['plan'] ?? [];
        $amount = (int) ($price['unit_amount'] ?? $price['amount'] ?? 0);

        if ($customerId === null || ! is_int($trialEnd) || $amount <= 0) {
            return null;
        }

        $user = User::query()->where('stripe_id', $customerId)->first();

        return $user === null ? null : new SendTrialEndingEmailJob(
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
    private function dedupeKey(array $payload): ?string
    {
        $id = $this->stringOrNull($payload['id'] ?? null);

        return $id === null ? null : 'trial-ending:stripe-event:'.$id;
    }

    /**
     * Hand the event back if the dispatch never happened, so Stripe's
     * redelivery gets another go rather than being silently swallowed.
     */
    private function release(?string $claim): void
    {
        if ($claim !== null) {
            Cache::forget($claim);
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
