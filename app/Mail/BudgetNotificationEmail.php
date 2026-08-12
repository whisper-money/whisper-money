<?php

namespace App\Mail;

use App\Enums\BudgetNotificationType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class BudgetNotificationEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public $backoff = [2, 5, 10, 30];

    public function __construct(
        public User $user,
        public Budget $budget,
        public BudgetPeriod $period,
        public BudgetNotificationType $type,
        public ?Transaction $transaction = null,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        $subject = match ($this->type) {
            BudgetNotificationType::NewTransaction => __('New transaction in ":budget"', ['budget' => $this->budget->name]),
            BudgetNotificationType::CloseToLimit => __('":budget" is close to its limit', ['budget' => $this->budget->name]),
            BudgetNotificationType::OverLimit => __('":budget" is over its limit', ['budget' => $this->budget->name]),
        };

        return new Envelope(
            from: new Address(
                config('mail.from.address', 'no-reply@whisper.money'),
                config('mail.from.name', 'Whisper Money'),
            ),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $currency = $this->user->currency_code ?? 'USD';
        $allocated = $this->period->allocated_amount;
        $spent = $this->period->spentAmount();
        $remaining = $allocated - $spent;
        $hasLimit = $allocated > 0;

        return new Content(
            markdown: 'mail.budget-notification',
            with: [
                'userName' => $this->user->name,
                'budgetName' => $this->budget->name,
                'type' => $this->type,
                'transaction' => $this->transaction,
                'periodStart' => $this->period->start_date,
                'periodEnd' => $this->period->end_date,
                'hasLimit' => $hasLimit,
                'allocatedFormatted' => Money::format($allocated, $currency),
                'spentFormatted' => Money::format($spent, $currency),
                'remainingFormatted' => Money::format(abs($remaining), $currency),
                // The owner's share, not the full charge: every other figure in
                // this email is what the budget counted, so a shared account
                // would otherwise show €80 spent against a €40 rise.
                'transactionAmountFormatted' => $this->transaction
                    ? Money::format(abs($this->transaction->ownerShareOf((int) $this->transaction->amount)), $currency)
                    : null,
                // Aligns with the service: at exactly the limit we are "over".
                'isOverLimit' => $hasLimit && $spent >= $allocated,
            ],
        );
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new RateLimited('emails'))->releaseAfter(1)];
    }
}
