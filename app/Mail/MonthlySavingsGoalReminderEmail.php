<?php

namespace App\Mail;

use App\Models\SavingsGoal;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once per month, a few days before it ends, when a monthly savings goal
 * is still short of its target. The subject names the goal but no amount: the
 * figures stay inside the message, like the budget alerts.
 */
class MonthlySavingsGoalReminderEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * A goal deleted before the email left has nothing left to remind about.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * @var int
     */
    public $tries = 5;

    /**
     * @var array<int, int>
     */
    public $backoff = [2, 5, 10, 30];

    public function __construct(
        public User $user,
        public SavingsGoal $goal,
        public string $month,
        public int $saved,
        public int $target,
        public int $daysLeft,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address', 'no-reply@whisper.money'),
                config('mail.from.name', 'Whisper Money'),
            ),
            subject: trans_choice('{1}:goal: 1 day left to reach this month\'s target|[2,*]:goal: :days days left to reach this month\'s target', $this->daysLeft, [
                'goal' => $this->goal->name,
                'days' => $this->daysLeft,
            ]),
        );
    }

    public function content(): Content
    {
        $currency = $this->user->currency_code ?? 'USD';

        return new Content(
            markdown: 'mail.monthly-savings-goal-reminder',
            with: [
                'userName' => $this->user->name,
                'goal' => $this->goal,
                'monthName' => Carbon::createFromFormat('Y-m-d', $this->month.'-01')
                    ->locale(app()->getLocale())
                    ->isoFormat('MMMM'),
                'daysLeft' => $this->daysLeft,
                'savedFormatted' => Money::format($this->saved, $currency),
                'targetFormatted' => Money::format($this->target, $currency),
                'remainingFormatted' => Money::format(max(0, $this->target - $this->saved), $currency),
            ],
        );
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new RateLimited('emails'))->releaseAfter(1)];
    }
}
