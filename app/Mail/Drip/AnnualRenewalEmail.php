<?php

namespace App\Mail\Drip;

use App\Models\User;
use App\Services\Subscriptions\YearInReview;
use App\Support\Money;
use Carbon\CarbonImmutable;

class AnnualRenewalEmail extends DripMail
{
    public function __construct(
        User $user,
        public CarbonImmutable $renewsAt,
        public int $amountInCents,
        public string $currency,
    ) {
        parent::__construct($user);
    }

    protected function dripSubject(): string
    {
        return __('Your Whisper Money annual plan renews on :date', ['date' => $this->localRenewal()->isoFormat('LL')]);
    }

    protected function template(): string
    {
        return 'mail.drip.annual-renewal';
    }

    protected function repliesToSender(): bool
    {
        return true;
    }

    /**
     * Not called `renewsAt`: a Mailable's public properties are assigned to the
     * view after this data, so the raw UTC value would overwrite the local one.
     *
     * @return array<string, mixed>
     */
    protected function contentData(): array
    {
        return [
            'renewsAtLocal' => $this->localRenewal(),
            'amount' => Money::format($this->amountInCents, $this->currency),
            'recap' => app(YearInReview::class)->linesFor($this->user, $this->renewsAt->subYear()),
        ];
    }

    /**
     * Stripe reports the renewal in UTC, which can be another day for the
     * reader; an unparseable legacy timezone falls back to UTC.
     */
    private function localRenewal(): CarbonImmutable
    {
        return rescue(
            fn () => $this->renewsAt->setTimezone($this->user->timezone ?? config('app.timezone')),
            $this->renewsAt,
            report: false,
        );
    }
}
