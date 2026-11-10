<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\TrialEndingCanceledEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;

class SendTrialEndingCanceledEmailJob extends SendDripEmailJob
{
    public function __construct(
        User $user,
        public CarbonImmutable $trialEndsAt,
    ) {
        parent::__construct($user);
    }

    protected function emailType(): DripEmailType
    {
        return DripEmailType::TrialEndingCanceled;
    }

    protected function buildMail(): Mailable
    {
        return new TrialEndingCanceledEmail($this->user, $this->trialEndsAt);
    }

    /**
     * Keyed on the trial, like {@see SendTrialEndingEmailJob}: one warning per
     * trial, however many times Stripe delivers the webhook.
     */
    protected function emailIdentifier(): string
    {
        return $this->trialEndsAt->toDateString();
    }

    /**
     * Someone who took the cancellation back before the queue got here is
     * paying now, and an email asking them to keep the plan would read as if
     * the click had not worked.
     */
    protected function shouldSend(): bool
    {
        return $this->user->subscription('default')?->onGracePeriod() ?? false;
    }
}
