<?php

namespace App\Services\Stripe;

use App\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * The invoice object of a Stripe webhook, read the same way whichever API
 * version sent it: dahlia puts the subscription under `parent` and leaves the
 * price off the lines, older versions have both at the top.
 */
class InvoicePayload
{
    /**
     * A billing period at least this long is a yearly one. Used when the line
     * item carries no price object, which is how newer Stripe API versions
     * send it: the shortest yearly period is 365 days, the longest monthly 31.
     */
    private const MIN_YEARLY_PERIOD_SECONDS = 300 * 86400;

    /** A renewal this close to the trial end is the first charge after the trial. */
    private const TRIAL_END_TOLERANCE_SECONDS = 86400;

    /**
     * @param  array<string, mixed>  $invoice
     */
    public function __construct(public readonly array $invoice) {}

    public function id(): ?string
    {
        return $this->string($this->invoice['id'] ?? null);
    }

    public function customerId(): ?string
    {
        return $this->string($this->invoice['customer'] ?? null);
    }

    public function subscriptionId(): ?string
    {
        return $this->string($this->invoice['subscription'] ?? $this->invoice['parent']['subscription_details']['subscription'] ?? null);
    }

    public function amountDue(): int
    {
        return (int) ($this->invoice['amount_due'] ?? 0);
    }

    public function currency(): string
    {
        return (string) ($this->invoice['currency'] ?? config('cashier.currency', 'eur'));
    }

    public function billingReason(): ?string
    {
        return $this->string($this->invoice['billing_reason'] ?? null);
    }

    /**
     * Whether Stripe still wants the money. A payload from before the invoice
     * was paid or voided says `open`; one redelivered late says so too, which
     * is why the rows are also closed by the events that settle them.
     */
    public function isOpen(): bool
    {
        return ($this->invoice['status'] ?? 'open') === 'open';
    }

    public function attemptCount(): int
    {
        return (int) ($this->invoice['attempt_count'] ?? 1);
    }

    public function nextPaymentAttemptAt(): ?CarbonImmutable
    {
        return $this->time($this->invoice['next_payment_attempt'] ?? null);
    }

    /**
     * When the charge first failed: now for the first attempt, the moment
     * the invoice was finalized (and first charged) for one first seen on a
     * later retry, so the reminders keep Stripe's calendar.
     */
    public function firstFailedAt(): CarbonImmutable
    {
        $finalizedAt = $this->time($this->invoice['status_transitions']['finalized_at'] ?? null);

        return $this->attemptCount() > 1 && $finalizedAt !== null ? $finalizedAt : CarbonImmutable::now();
    }

    public function billingInterval(): ?string
    {
        return self::lineInterval($this->invoice['lines']['data'][0] ?? []);
    }

    /**
     * Whether the invoice is the renewal that starts the first paid period
     * after a trial. The invoice that creates the subscription is not: with a
     * trial it is the $0 one, without one there was no trial to speak of.
     */
    public function startsAfterTrial(Subscription $subscription): bool
    {
        $periodStart = $this->invoice['lines']['data'][0]['period']['start'] ?? $this->invoice['period_end'] ?? null;

        return $this->billingReason() === 'subscription_cycle'
            && $subscription->trial_ends_at !== null
            && is_int($periodStart)
            && abs($periodStart - $subscription->trial_ends_at->getTimestamp()) <= self::TRIAL_END_TOLERANCE_SECONDS;
    }

    /**
     * The first invoice of a subscription started through Cashier's Checkout,
     * which tags it until that invoice is paid: the customer is on the page
     * that asks for the bank's confirmation, so an email would only repeat
     * it. A renewal is never on-session, whatever the tag says.
     */
    public function isOnSessionCheckout(): bool
    {
        return $this->billingReason() === 'subscription_create'
            && ((bool) ($this->invoice['metadata']['is_on_session_checkout'] ?? false)
                || (bool) ($this->invoice['parent']['subscription_details']['metadata']['is_on_session_checkout'] ?? false));
    }

    /**
     * `month` or `year` for a subscription line. Older API versions put the
     * price on the line; newer ones only send its id, so the length of the
     * billing period decides instead.
     *
     * @param  array<string, mixed>  $line
     */
    public static function lineInterval(array $line): ?string
    {
        $interval = $line['price']['recurring']['interval'] ?? $line['plan']['interval'] ?? null;

        if (is_string($interval)) {
            return $interval;
        }

        $period = (int) ($line['period']['end'] ?? 0) - (int) ($line['period']['start'] ?? 0);

        return match (true) {
            $period <= 0 => null,
            $period >= self::MIN_YEARLY_PERIOD_SECONDS => 'year',
            default => 'month',
        };
    }

    private function time(mixed $timestamp): ?CarbonImmutable
    {
        return is_int($timestamp) ? CarbonImmutable::createFromTimestamp($timestamp) : null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
