<?php

namespace App\Mail\Drip;

use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The heads-up a few days before a paused plan resumes on its own: the day,
 * and what will be charged then, so the charge never comes as a surprise.
 * Billing mail, so it is not marketing and always goes out.
 */
class SubscriptionResumingEmail extends DripMail
{
    public function __construct(
        User $user,
        public CarbonImmutable $resumesAt,
        public int $amountInCents,
        public string $currency,
    ) {
        parent::__construct($user);
    }

    protected function dripSubject(): string
    {
        return __('Your Whisper Money plan resumes in 3 days');
    }

    protected function template(): string
    {
        return 'mail.drip.subscription-resuming';
    }

    protected function repliesToSender(): bool
    {
        return true;
    }

    /**
     * `resumesOn`, not `resumesAt`: a Mailable's public properties overwrite
     * the view data of the same name (see {@see TrialEndingEmail::contentData()}).
     *
     * @return array<string, mixed>
     */
    protected function contentData(): array
    {
        return [
            'resumesOn' => $this->resumesAt
                ->setTimezone($this->user->localTimezone())
                ->locale(app()->getLocale())
                ->isoFormat('LL'),
            'amount' => Money::format($this->amountInCents, $this->currency),
        ];
    }
}
