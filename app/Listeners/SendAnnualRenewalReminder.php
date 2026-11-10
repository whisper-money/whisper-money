<?php

namespace App\Listeners;

use App\Jobs\Drip\SendAnnualRenewalEmailJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Events\WebhookReceived;
use Laravel\Cashier\Subscription;
use Throwable;

/**
 * Gives an annual subscriber a heads-up before the yearly charge, with what
 * they got out of the year. A surprise annual charge is where refunds and
 * chargebacks come from; a reminder that states the date and the amount is
 * also what EU consumer rules expect.
 *
 * Stripe sends `invoice.upcoming` ahead of every renewal (the lead time is set
 * in the Stripe dashboard), monthly ones included, so most of what arrives here
 * is filtered out: only a yearly plan with something to charge, not set to
 * cancel and not still in its trial, belonging to a user we know.
 */
class SendAnnualRenewalReminder
{
    private const DEDUPE_TTL_HOURS = 24;

    /**
     * A billing period at least this long is a yearly one. Used when the line
     * item carries no price object, which is how newer Stripe API versions
     * send it: the shortest yearly period is 365 days, the longest monthly 31.
     */
    private const MIN_YEARLY_PERIOD_SECONDS = 300 * 86400;

    public function handle(WebhookReceived $event): void
    {
        if (($event->payload['type'] ?? null) !== 'invoice.upcoming' || ! config('mail.drip_emails_enabled')) {
            return;
        }

        $job = $this->buildJob($event->payload['data']['object'] ?? []);

        if ($job === null) {
            return;
        }

        $eventId = $event->payload['id'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            Bus::dispatch($job);

            return;
        }

        $claim = 'annual-renewal:stripe-event:'.$eventId;

        if (Cache::add($claim, true, now()->addHours(self::DEDUPE_TTL_HOURS))) {
            $this->dispatchOrRelease($job, $claim);
        }
    }

    /**
     * Turn the upcoming invoice into the email to send, or null when it is not
     * an annual renewal worth warning about.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function buildJob(array $invoice): ?SendAnnualRenewalEmailJob
    {
        $amount = (int) ($invoice['amount_due'] ?? 0);
        $renewsAt = $this->yearlyLine($invoice)['period']['start'] ?? null;

        if ($amount <= 0 || ! is_int($renewsAt)) {
            return null;
        }

        $user = $this->renewingUser($invoice);

        return $user === null ? null : new SendAnnualRenewalEmailJob(
            $user,
            CarbonImmutable::createFromTimestamp($renewsAt),
            $amount,
            (string) ($invoice['currency'] ?? 'eur'),
        );
    }

    /**
     * The customer we know, provided the subscription the invoice renews is
     * theirs and still renews. The invoice does not say whether the
     * subscription is set to cancel at period end, so that is read from the
     * local copy Cashier keeps in step through its own webhooks: a scheduled
     * cancellation shows there as an `ends_at`. Only an `active` one renews: a
     * trial is left to the trial-ending email, and a past-due or unpaid one has
     * a bigger problem than its renewal date.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function renewingUser(array $invoice): ?User
    {
        $customerId = $invoice['customer'] ?? null;
        $subscriptionId = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? null;

        if (! is_string($customerId) || ! is_string($subscriptionId)) {
            return null;
        }

        $user = User::query()->where('stripe_id', $customerId)->first();

        /** @var Subscription|null $subscription */
        $subscription = $user?->subscriptions()->where('stripe_id', $subscriptionId)->first();

        if ($subscription?->stripe_status !== 'active' || $subscription->ends_at !== null) {
            return null;
        }

        return $user;
    }

    /**
     * The line that renews a yearly price, whose period start is the renewal
     * date. Picked out rather than taking the first line, since an upcoming
     * invoice can also carry proration or one-off items that start today.
     * Older API versions put the price on each line; newer ones only send its
     * id, so the length of the billing period decides instead.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>|null
     */
    private function yearlyLine(array $invoice): ?array
    {
        foreach ($invoice['lines']['data'] ?? [] as $line) {
            $interval = $line['price']['recurring']['interval'] ?? $line['plan']['interval'] ?? null;
            $period = (int) ($line['period']['end'] ?? 0) - (int) ($line['period']['start'] ?? 0);

            if ($interval === 'year' || ($interval === null && $period >= self::MIN_YEARLY_PERIOD_SECONDS)) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Stripe retries deliveries, so the first one wins. The mail log keyed on
     * the renewal date is the durable guard; the claim only covers two
     * deliveries in flight at once, and is handed back if the dispatch fails
     * so Stripe's redelivery gets another go.
     */
    private function dispatchOrRelease(SendAnnualRenewalEmailJob $job, string $claim): void
    {
        try {
            Bus::dispatch($job);
        } catch (Throwable $exception) {
            Cache::forget($claim);

            throw $exception;
        }
    }
}
