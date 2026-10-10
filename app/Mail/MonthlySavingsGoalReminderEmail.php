<?php

namespace App\Mail;

use App\Models\User;
use App\Services\SavingsGoals\MonthlySavingsGoalNotifier;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sent once per month, a few days before it ends, listing every monthly
 * savings goal still short of its target. It carries no amount anywhere —
 * only how far along each goal is, as a share of its target — for the same
 * reason the monthly summary email carries none: an inbox is not the app.
 *
 * The goals' periods were claimed before this was queued; if delivery gives
 * up for good, the claims are released so the next run sends it again.
 */
class MonthlySavingsGoalReminderEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

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
        public string $month,
        /** @var list<array{name: string, percent: int}> */
        public array $goals,
        public int $daysLeft,
        /** @var list<string> */
        public array $periodIds,
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
            subject: trans_choice('{1}1 day left to reach this month\'s savings targets|[2,*]:days days left to reach this month\'s savings targets', $this->daysLeft, [
                'days' => $this->daysLeft,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.monthly-savings-goal-reminder',
            with: [
                'userName' => $this->user->name,
                'goals' => $this->goals,
                'monthName' => Carbon::createFromFormat('Y-m-d', $this->month.'-01')
                    ->locale(app()->getLocale())
                    ->isoFormat('MMMM'),
                'daysLeft' => $this->daysLeft,

            ],
        );
    }

    /**
     * Delivery gave up: hand the goals back to the next run.
     */
    public function failed(Throwable $exception): void
    {
        MonthlySavingsGoalNotifier::release($this->periodIds, 'reminder_notified_at');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new RateLimited('emails'))->releaseAfter(1)];
    }
}
