<?php

namespace App\Mail\Drip;

use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

class TrialEndingEmail extends DripMail
{
    public function __construct(
        User $user,
        public CarbonImmutable $trialEndsAt,
        public int $amountInCents,
        public string $currency,
    ) {
        parent::__construct($user);
    }

    protected function dripSubject(): string
    {
        return __('Your free trial ends in 3 days');
    }

    protected function template(): string
    {
        return 'mail.drip.trial-ending';
    }

    protected function repliesToSender(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentData(): array
    {
        return [
            'trialEndsAt' => $this->localTrialEnd(),
            'amount' => Money::format($this->amountInCents, $this->currency),
        ];
    }

    /**
     * Stripe reports the trial end in UTC, which can land on the previous or
     * next day for the reader. Both write paths validate the stored timezone,
     * so an unparseable one only comes from legacy rows and falls back to UTC.
     */
    private function localTrialEnd(): CarbonImmutable
    {
        return rescue(
            fn () => $this->trialEndsAt->setTimezone($this->user->timezone ?? config('app.timezone')),
            $this->trialEndsAt,
            report: false,
        );
    }
}
