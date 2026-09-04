<?php

namespace App\Notifications;

use App\Models\MonthlySummary;
use App\Models\User;
use App\Services\MonthlySummary\EmailPresenter;
use Illuminate\Notifications\Notification;

/**
 * Rings the bell for a report that has just gone out.
 *
 * Database only: the report itself travels by email through the drip pipeline,
 * and a second copy of it here would be noise. The row carries just enough to
 * draw its line in the bell and open the report; the figures stay frozen on the
 * summary.
 */
class MonthlySummaryReady extends Notification
{
    public function __construct(public MonthlySummary $summary) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'summary_id' => $this->summary->id,
            'space_id' => $this->summary->space_id,
            'period' => $this->summary->period,
            // Written in the reader's language at send time, like the email it
            // announces: a headline is a sentence about a closed month, not a
            // figure to re-derive on every render.
            'headline' => app(EmailPresenter::class)->present($this->summary, $notifiable->preferredLocale())['headline'],
        ];
    }
}
