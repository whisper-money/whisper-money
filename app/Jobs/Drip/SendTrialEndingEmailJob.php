<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\TrialEndingEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;

class SendTrialEndingEmailJob extends SendDripEmailJob
{
    public function __construct(
        User $user,
        public CarbonImmutable $trialEndsAt,
        public int $amountInCents,
        public string $currency,
    ) {
        parent::__construct($user);
    }

    protected function emailType(): DripEmailType
    {
        return DripEmailType::TrialEnding;
    }

    protected function buildMail(): Mailable
    {
        return new TrialEndingEmail($this->user, $this->trialEndsAt, $this->amountInCents, $this->currency);
    }

    /**
     * Keyed on the trial rather than the type, so someone who starts a second
     * trial later still gets warned, while a redelivered webhook does not
     * produce a second email for the same trial.
     */
    protected function emailIdentifier(): string
    {
        return $this->trialEndsAt->toDateString();
    }
}
