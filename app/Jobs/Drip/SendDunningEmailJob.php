<?php

namespace App\Jobs\Drip;

use App\Enums\DunningStep;
use App\Models\FailedInvoice;
use App\Models\Subscription;
use App\Models\User;
use Stripe\Subscription as StripeSubscription;

/**
 * Sends one step of the dunning sequence for one failed invoice. The mail log
 * is keyed on the invoice and the step, so every step goes out once per
 * invoice however many webhooks or scheduler runs ask for it.
 *
 * Whether the invoice is still owed is checked again when the job runs, from
 * the local copies the webhooks keep up to date: a payment that went through
 * in the meantime must not be chased.
 */
abstract class SendDunningEmailJob extends SendDripEmailJob
{
    public function __construct(
        User $user,
        public FailedInvoice $invoice,
        public DunningStep $step,
    ) {
        parent::__construct($user);
    }

    /**
     * The email for where the invoice is: a payment the bank holds for 3D
     * Secure is not fixed by a new card, so it is asked to be confirmed.
     */
    public static function for(FailedInvoice $invoice, DunningStep $step): self
    {
        return $invoice->action_required_at !== null
            ? new SendPaymentActionRequiredEmailJob($invoice->user, $invoice, $step)
            : new SendPaymentFailedEmailJob($invoice->user, $invoice, $step);
    }

    /**
     * Subscription statuses the first email of the sequence goes out in.
     *
     * @return list<string>
     */
    abstract protected function firstEmailStatuses(): array;

    protected function emailIdentifier(): string
    {
        return $this->invoice->stripe_invoice_id.':'.$this->step->value;
    }

    protected function shouldSend(): bool
    {
        $this->invoice->refresh();
        /** @var Subscription|null $subscription */
        $subscription = $this->invoice->subscription;

        if (! $this->invoice->isOpen() || $subscription === null || $subscription->ended()) {
            return false;
        }

        // A reminder only while Stripe is still retrying, which is what
        // `past_due` means, and not to someone who already chose to leave.
        if ($this->step->isReminder()) {
            return $subscription->stripe_status === StripeSubscription::STATUS_PAST_DUE && ! $subscription->canceled();
        }

        return in_array($subscription->stripe_status, $this->firstEmailStatuses(), true);
    }
}
