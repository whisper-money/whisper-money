<?php

namespace App\Mail\Drip;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/**
 * The nudge that goes out on the 3rd when a month is not ready to report.
 *
 * Its premise is deliberately truthful. The report goes out on the 10th whatever
 * happens, so telling the reader they will not get it unless they act would work
 * exactly once; instead it names the date and offers a better report to whoever
 * updates before it.
 */
class MonthlySummaryReminderEmail extends DripMail
{
    public function __construct(
        User $user,
        public Carbon $month,
        public Carbon $deadline,
    ) {
        parent::__construct($user);
    }

    protected function dripSubject(): string
    {
        return __('Your :month summary is waiting on your data', ['month' => $this->monthName()]);
    }

    protected function template(): string
    {
        return 'mail.drip.monthly-summary-reminder';
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentData(): array
    {
        return [
            'monthName' => $this->monthName(),
            'deadline' => $this->deadline->locale(app()->getLocale())->isoFormat('D MMMM'),
            'unsubscribeUrl' => $this->unsubscribeUrl(),
        ];
    }

    private function unsubscribeUrl(): string
    {
        return URL::signedRoute('monthly-summaries.unsubscribe', ['user' => $this->user->id]);
    }

    private function monthName(): string
    {
        return $this->month->copy()->locale(app()->getLocale())->isoFormat('MMMM');
    }
}
