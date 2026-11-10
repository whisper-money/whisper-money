<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\AnnualRenewalEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;

class SendAnnualRenewalEmailJob extends SendDripEmailJob
{
    public function __construct(
        User $user,
        public CarbonImmutable $renewsAt,
        public int $amountInCents,
        public string $currency,
    ) {
        parent::__construct($user);
    }

    protected function emailType(): DripEmailType
    {
        return DripEmailType::AnnualRenewal;
    }

    protected function buildMail(): Mailable
    {
        return new AnnualRenewalEmail($this->user, $this->renewsAt, $this->amountInCents, $this->currency);
    }

    /**
     * Keyed on the renewal, so every year gets its reminder while a
     * redelivered webhook does not produce a second one for the same renewal.
     */
    protected function emailIdentifier(): string
    {
        return $this->renewsAt->toDateString();
    }
}
