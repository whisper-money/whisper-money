<?php

namespace App\Listeners;

use App\Enums\DunningStep;
use App\Jobs\Drip\SendDunningEmailJob;
use App\Models\FailedInvoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Stripe\InvoicePayload;
use App\Services\Stripe\InvoicePaymentIntentResolver;
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
 *   confirmation and queues the "confirm your payment" email instead.
 * - A paid, voided or written-off invoice, or a deleted subscription, closes
 *   it: nothing is chased after that.
 *
 * Only renewals and first charges of a subscription we know are dunned, and
 * only for an amount: a $0 invoice or a one-off one never gets here.
 */
class SyncFailedInvoicesFromStripe
{
    /**
     * The first email waits this long, for two reasons: Stripe sends
     * `invoice.payment_action_required` alongside the failure when the bank
     * wants a confirmation, which must replace the "declined" email; and the
     * subscription's own update to `past_due`, which the email requires, may
     * land after the invoice event.
     */
    private const FIRST_EMAIL_DELAY_MINUTES = 10;

    private const DUNNED_BILLING_REASONS = ['subscription_cycle', 'subscription_create'];

    private const RESOLVING_EVENTS = ['invoice.paid', 'invoice.payment_succeeded', 'invoice.voided', 'invoice.marked_uncollectible'];

    public function __construct(private InvoicePaymentIntentResolver $paymentIntents) {}

    public function handle(WebhookReceived $event): void
    {
        $type = $event->payload['type'] ?? null;
        $object = $event->payload['data']['object'] ?? [];
        $stripeId = is_string($object['id'] ?? null) ? $object['id'] : '';

        match (true) {
            $type === 'invoice.payment_failed' => $this->recordFailure(new InvoicePayload($object)),
            $type === 'invoice.payment_action_required' => $this->recordActionRequired(new InvoicePayload($object)),
            in_array($type, self::RESOLVING_EVENTS, true) => $this->resolveInvoice($stripeId),
            $type === 'customer.subscription.deleted' => $this->resolveSubscription($stripeId),
            default => null,
        };
    }

    private function recordFailure(InvoicePayload $invoice): void
    {
        $failedInvoice = $this->syncOpenRow($invoice);

        // A redelivered first failure still gets its email; a later retry's
        // failure does not restart the sequence. A row first seen on a later
        // retry (one that failed before this shipped) starts it.
        if ($failedInvoice !== null && ($failedInvoice->wasRecentlyCreated || $invoice->attemptCount() <= 1)) {
            $this->queueFirstEmail($failedInvoice);
        }
    }

    private function recordActionRequired(InvoicePayload $invoice): void
    {
        $failedInvoice = $invoice->isOnSessionCheckout() ? null : $this->syncOpenRow($invoice);

        if ($failedInvoice === null) {
            return;
        }

        $failedInvoice->forceFill([
            'action_required_at' => $failedInvoice->action_required_at ?? now(),
            'payment_intent_id' => $this->paymentIntents->for($invoice->invoice) ?? $failedInvoice->payment_intent_id,
        ])->save();

        $this->queueFirstEmail($failedInvoice);
    }

    /**
     * The row for a dunned invoice, created on its first failure and brought
     * up to date on every later one. Null for an invoice we do not dun, and
     * for one already closed, which an out-of-order event must not reopen.
     */
    private function syncOpenRow(InvoicePayload $invoice): ?FailedInvoice
    {
        $subscription = $this->dunnedSubscription($invoice);

        if ($subscription === null) {
            return null;
        }

        $failedInvoice = FailedInvoice::query()->createOrFirst(
            ['stripe_invoice_id' => $invoice->id()],
            [
                'user_id' => $subscription->user_id,
                'stripe_subscription_id' => $subscription->stripe_id,
                'amount_due' => $invoice->amountDue(),
                'currency' => $invoice->currency(),
                'billing_interval' => $invoice->billingInterval(),
                'is_first_charge_after_trial' => $invoice->startsAfterTrial($subscription),
                'attempt_count' => $invoice->attemptCount(),
                'first_failed_at' => $invoice->firstFailedAt(),
                'next_payment_attempt_at' => $invoice->nextPaymentAttemptAt(),
            ],
        );

        if (! $failedInvoice->isOpen()) {
            return null;
        }

        // A late redelivery of an earlier retry must not wind the count back.
        if (! $failedInvoice->wasRecentlyCreated && $invoice->attemptCount() >= $failedInvoice->attempt_count) {
            $failedInvoice->forceFill([
                'attempt_count' => $invoice->attemptCount(),
                'next_payment_attempt_at' => $invoice->nextPaymentAttemptAt(),
            ])->save();
        }

        return $failedInvoice;
    }

    /**
     * The local subscription a dunned invoice bills: a renewal or first charge
     * with an amount, still owed, of a subscription that belongs to the
     * customer we know.
     */
    private function dunnedSubscription(InvoicePayload $invoice): ?Subscription
    {
        $isDunned = $invoice->id() !== null
            && $invoice->amountDue() > 0
            && $invoice->isOpen()
            && in_array($invoice->billingReason(), self::DUNNED_BILLING_REASONS, true);

        if (! $isDunned || $invoice->customerId() === null || $invoice->subscriptionId() === null) {
            return null;
        }

        $user = User::query()->where('stripe_id', $invoice->customerId())->first();

        /** @var Subscription|null */
        return $user?->subscriptions()->where('stripe_id', $invoice->subscriptionId())->first();
    }

    private function resolveInvoice(string $invoiceId): void
    {
        FailedInvoice::query()->open()->where('stripe_invoice_id', $invoiceId)->update(['resolved_at' => now()]);
    }

    private function resolveSubscription(string $subscriptionId): void
    {
        FailedInvoice::query()->open()->where('stripe_subscription_id', $subscriptionId)->update(['resolved_at' => now()]);
    }

    private function queueFirstEmail(FailedInvoice $failedInvoice): void
    {
        if (! config('mail.drip_emails_enabled')) {
            return;
        }

        dispatch(
            SendDunningEmailJob::for($failedInvoice, DunningStep::First)
                ->delay(now()->addMinutes(self::FIRST_EMAIL_DELAY_MINUTES)),
        );
    }
}
