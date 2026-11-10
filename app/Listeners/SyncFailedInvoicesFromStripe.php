<?php

namespace App\Listeners;

use App\Enums\DunningStep;
use App\Jobs\Drip\SendPaymentActionRequiredEmailJob;
use App\Jobs\Drip\SendPaymentFailedEmailJob;
use App\Models\FailedInvoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Stripe\InvoicePaymentIntentResolver;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Our own dunning, in place of Stripe's generic "card payment failed" email
 * and its (switched off) 3D Secure email: keeps {@see FailedInvoice} in step
 * with Stripe and sends the first email of the sequence. The reminders come
 * from `email:payment-failed-reminders`, which reads only these rows.
 *
 * - `invoice.payment_failed` opens the row (every retry updates it) and, on
 *   the first failure, queues the "payment failed" email.
 * - `invoice.payment_action_required` marks the bank asking for a 3D Secure
 *   confirmation and queues the "confirm your payment" email.
 * - A paid, voided or written-off invoice, or a deleted subscription, closes
 *   it: nothing is chased after that.
 *
 * Only renewals and first charges of a subscription we know are dunned, and
 * only for an amount: a $0 invoice or a one-off one never gets here.
 */
class SyncFailedInvoicesFromStripe
{
    /**
     * Long enough for `invoice.payment_action_required`, which Stripe sends
     * alongside the failure when the bank wants a confirmation, to land first
     * and replace the "declined" email with the confirmation one.
     */
    private const FIRST_EMAIL_DELAY_MINUTES = 10;

    private const DUNNED_BILLING_REASONS = ['subscription_cycle', 'subscription_create'];

    private const RESOLVING_EVENTS = ['invoice.paid', 'invoice.payment_succeeded', 'invoice.voided', 'invoice.marked_uncollectible'];

    /** A renewal this close to the trial end is the first charge after the trial. */
    private const TRIAL_END_TOLERANCE_SECONDS = 86400;

    public function __construct(private InvoicePaymentIntentResolver $paymentIntents) {}

    public function handle(WebhookReceived $event): void
    {
        $type = $event->payload['type'] ?? null;
        $object = $event->payload['data']['object'] ?? [];

        match (true) {
            $type === 'invoice.payment_failed' => $this->recordFailure($object),
            $type === 'invoice.payment_action_required' => $this->recordActionRequired($object),
            in_array($type, self::RESOLVING_EVENTS, true) => $this->resolve('stripe_invoice_id', $object['id'] ?? null),
            $type === 'customer.subscription.deleted' => $this->resolve('stripe_subscription_id', $object['id'] ?? null),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function recordFailure(array $invoice): void
    {
        $failedInvoice = $this->open($invoice);

        if ($failedInvoice === null) {
            return;
        }

        // A redelivered first failure still gets its email; a later retry's
        // failure does not restart the sequence.
        if ($failedInvoice->wasRecentlyCreated || $failedInvoice->attempt_count <= 1) {
            $this->queue(
                new SendPaymentFailedEmailJob($failedInvoice->user, $failedInvoice, DunningStep::First),
                delayMinutes: self::FIRST_EMAIL_DELAY_MINUTES,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function recordActionRequired(array $invoice): void
    {
        if ($this->isOnSessionCheckout($invoice)) {
            return;
        }

        $failedInvoice = $this->open($invoice);

        if ($failedInvoice === null) {
            return;
        }

        $failedInvoice->forceFill([
            'action_required_at' => $failedInvoice->action_required_at ?? now(),
            'payment_intent_id' => $this->paymentIntents->for($invoice) ?? $failedInvoice->payment_intent_id,
        ])->save();

        $this->queue(new SendPaymentActionRequiredEmailJob($failedInvoice->user, $failedInvoice, DunningStep::First));
    }

    /**
     * The row for a dunned invoice, created on its first failure and brought
     * up to date on every later one. Null for an invoice we do not dun, and
     * for one already closed, which an out-of-order event must not reopen.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function open(array $invoice): ?FailedInvoice
    {
        $subscription = $this->dunnedSubscription($invoice);

        if ($subscription === null) {
            return null;
        }

        $progress = [
            'attempt_count' => (int) ($invoice['attempt_count'] ?? 1),
            'next_payment_attempt_at' => $this->timestamp($invoice['next_payment_attempt'] ?? null),
        ];

        $failedInvoice = FailedInvoice::query()->createOrFirst(
            ['stripe_invoice_id' => $invoice['id']],
            [...$this->describe($invoice, $subscription), ...$progress],
        );

        if (! $failedInvoice->isOpen()) {
            return null;
        }

        if (! $failedInvoice->wasRecentlyCreated) {
            $failedInvoice->forceFill($progress)->save();
        }

        return $failedInvoice;
    }

    /**
     * The local subscription a dunned invoice bills: a renewal or first charge
     * with an amount, of a subscription that belongs to the customer we know.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function dunnedSubscription(array $invoice): ?Subscription
    {
        $subscriptionId = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? null;
        $customerId = $invoice['customer'] ?? null;

        if (! is_string($invoice['id'] ?? null)
            || ! is_string($subscriptionId) || ! is_string($customerId)
            || (int) ($invoice['amount_due'] ?? 0) <= 0
            || ! in_array($invoice['billing_reason'] ?? null, self::DUNNED_BILLING_REASONS, true)) {
            return null;
        }

        $user = User::query()->where('stripe_id', $customerId)->first();

        /** @var Subscription|null */
        return $user?->subscriptions()->where('stripe_id', $subscriptionId)->first();
    }

    /**
     * What the emails quote, fixed when the invoice first fails.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function describe(array $invoice, Subscription $subscription): array
    {
        $line = $invoice['lines']['data'][0] ?? [];

        return [
            'user_id' => $subscription->user_id,
            'stripe_subscription_id' => $subscription->stripe_id,
            'amount_due' => (int) $invoice['amount_due'],
            'currency' => (string) ($invoice['currency'] ?? config('cashier.currency', 'eur')),
            'billing_interval' => $this->billingInterval($line),
            'is_first_charge' => $this->isFirstCharge($invoice, $line, $subscription),
            'first_failed_at' => now(),
        ];
    }

    /**
     * The first charge is the invoice that creates the subscription or, after
     * a trial, the renewal that starts the first paid period.
     *
     * @param  array<string, mixed>  $invoice
     * @param  array<string, mixed>  $line
     */
    private function isFirstCharge(array $invoice, array $line, Subscription $subscription): bool
    {
        if ($invoice['billing_reason'] === 'subscription_create') {
            return true;
        }

        $periodStart = $line['period']['start'] ?? $invoice['period_end'] ?? null;

        return $subscription->trial_ends_at !== null
            && is_int($periodStart)
            && abs($periodStart - $subscription->trial_ends_at->getTimestamp()) <= self::TRIAL_END_TOLERANCE_SECONDS;
    }

    /**
     * Older API versions put the price on the line; newer ones only send its
     * id, so the length of the period decides instead (the shortest yearly
     * one is 365 days, the longest monthly 31).
     *
     * @param  array<string, mixed>  $line
     */
    private function billingInterval(array $line): ?string
    {
        $interval = $line['price']['recurring']['interval'] ?? $line['plan']['interval'] ?? null;

        if (is_string($interval)) {
            return $interval;
        }

        $period = (int) ($line['period']['end'] ?? 0) - (int) ($line['period']['start'] ?? 0);

        return $period <= 0 ? null : ($period >= 300 * 86400 ? 'year' : 'month');
    }

    /**
     * A payment confirmed during checkout: the customer is on the page that
     * asks for the confirmation, so an email would only repeat it.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function isOnSessionCheckout(array $invoice): bool
    {
        return (bool) ($invoice['metadata']['is_on_session_checkout']
            ?? $invoice['parent']['subscription_details']['metadata']['is_on_session_checkout']
            ?? false);
    }

    private function resolve(string $column, mixed $stripeId): void
    {
        if (! is_string($stripeId) || $stripeId === '') {
            return;
        }

        FailedInvoice::query()->open()->where($column, $stripeId)->update(['resolved_at' => now()]);
    }

    private function queue(SendPaymentFailedEmailJob|SendPaymentActionRequiredEmailJob $job, int $delayMinutes = 0): void
    {
        if (! config('mail.drip_emails_enabled')) {
            return;
        }

        dispatch($job->delay($delayMinutes > 0 ? now()->addMinutes($delayMinutes) : null));
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_int($value) ? CarbonImmutable::createFromTimestamp($value) : null;
    }
}
