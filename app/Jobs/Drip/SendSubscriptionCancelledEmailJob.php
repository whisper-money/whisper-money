<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\CancelledBeforePayingEmail;
use App\Mail\Drip\SubscriptionCancelledEmail;
use Illuminate\Mail\Mailable;
use Laravel\Cashier\Cashier;

/**
 * The email for a user whose subscription has ended, in one of two variants:
 * someone who paid us gets the win-back offer, someone who left before the
 * first charge (a cancelled trial, a first payment that never went through)
 * gets a short note asking what was missing, with no discount.
 *
 * Both share one type, so a user only ever gets one of them.
 */
class SendSubscriptionCancelledEmailJob extends SendDripEmailJob
{
    /**
     * The variant is picked by asking Stripe, so an outage is waited out
     * rather than retried on the spot.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900, 3600];

    public int $tries = 5;

    protected function emailType(): DripEmailType
    {
        return DripEmailType::SubscriptionCancelled;
    }

    protected function shouldSend(): bool
    {
        return $this->ineligibilityReason() === null;
    }

    /**
     * Why this user must not get the email, or null when they may. Nobody
     * still on a plan hears they left (including one cancelled but still
     * inside its paid period), and the shared accounts (demo, press and the
     * seeded fixtures) are never written to. Public so the manual command can
     * say why it skipped someone instead of reporting a send that never
     * happens.
     */
    public function ineligibilityReason(): ?string
    {
        return match (true) {
            $this->user->cannotUseStripe() => 'Shared account',
            $this->user->subscribed('default'), $this->user->hasActiveSubscriptionOrTrial() => 'Still subscribed',
            $this->user->hasReceivedEmail($this->emailType()) => 'Already sent',
            ! $this->user->wantsMarketingEmails() => 'Opted out of news and offers',
            default => null,
        };
    }

    protected function buildMail(): Mailable
    {
        return $this->hasEverPaid()
            ? new SubscriptionCancelledEmail($this->user)
            : new CancelledBeforePayingEmail($this->user);
    }

    /**
     * Whether Stripe ever collected money from this customer. Asked of Stripe
     * rather than read off the local subscription, because the local row only
     * knows about the latest subscription and not whether its invoices were
     * paid. A trial opens with a paid invoice for zero, so only an invoice that
     * actually took money counts.
     *
     * A Stripe outage throws, and the job is retried rather than guessing.
     */
    private function hasEverPaid(): bool
    {
        if (! $this->user->hasStripeId()) {
            return false;
        }

        $invoices = Cashier::stripe()->invoices->all([
            'customer' => $this->user->stripe_id,
            'status' => 'paid',
            'limit' => 100,
        ]);

        foreach ($invoices->autoPagingIterator() as $invoice) {
            if ($invoice->amount_paid > 0) {
                return true;
            }
        }

        return false;
    }
}
