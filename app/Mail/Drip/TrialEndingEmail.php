<?php

namespace App\Mail\Drip;

use App\Mail\Concerns\LocalisesTrialEnd;
use App\Models\User;
use App\Services\Subscriptions\TrialProgress;
use App\Support\Money;
use Carbon\CarbonImmutable;

class TrialEndingEmail extends DripMail
{
    use LocalisesTrialEnd;

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
        return __('Your free trial ends on :date', ['date' => $this->localTrialEnd()->isoFormat('LL')]);
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
     * The trial's progress is counted when the email is rendered rather than
     * when the webhook landed, so the numbers are the ones the reader finds if
     * they open the app.
     *
     * Deliberately not called `trialEndsAt`: a Mailable's public properties are
     * assigned to the view *after* the data passed to Content::with, so a key
     * that shares a name with the promoted `$trialEndsAt` above is silently
     * overwritten by the raw UTC value and the localisation below never
     * reaches the template.
     *
     * @return array<string, mixed>
     */
    protected function contentData(): array
    {
        return [
            'trialEndsAtLocal' => $this->localTrialEnd(),
            'amount' => Money::format($this->amountInCents, $this->currency),
            'progress' => app(TrialProgress::class)->for($this->user, $this->user->subscription('default')),
        ];
    }
}
