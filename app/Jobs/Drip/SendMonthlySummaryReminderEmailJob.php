<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\MonthlySummaryReminderEmail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Mail\Mailable;

/**
 * The nudge that goes out on the 3rd when a reader's month is not ready.
 *
 * A nudge, so it obeys the shared cooldown in {@see SendDripEmailJob}: the
 * manual-only reader is the audience of nearly every nudge we have, and
 * `inactive_no_bank` fires on its own schedule. Whichever gets there first wins.
 */
class SendMonthlySummaryReminderEmailJob extends SendDripEmailJob
{
    public function __construct(User $user, public string $period, public string $deadline)
    {
        parent::__construct($user);
    }

    protected function emailType(): DripEmailType
    {
        return DripEmailType::MonthlySummaryReminder;
    }

    protected function emailIdentifier(): string
    {
        return $this->period;
    }

    protected function shouldSend(): bool
    {
        return $this->user->wantsMonthlySummaryEmail();
    }

    protected function buildMail(): Mailable
    {
        return new MonthlySummaryReminderEmail(
            $this->user,
            Carbon::createFromFormat('Y-m-d', $this->period.'-01')->startOfMonth(),
            Carbon::parse($this->deadline),
        );
    }
}
