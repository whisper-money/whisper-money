<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\SubscriptionResumingEmail;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;

class SendSubscriptionResumingEmailJob extends SendDripEmailJob
{
    public function __construct(
        User $user,
        public Subscription $subscription,
        public CarbonImmutable $resumesAt,
        public int $amountInCents,
        public string $currency,
    ) {
        parent::__construct($user);
    }

    protected function emailType(): DripEmailType
    {
        return DripEmailType::SubscriptionResuming;
    }

    protected function buildMail(): Mailable
    {
        return new SubscriptionResumingEmail($this->user, $this->resumesAt, $this->amountInCents, $this->currency);
    }

    /**
     * Keyed on the pause, so every pause gets its one warning and the daily
     * command can re-dispatch through the whole window for free.
     */
    protected function emailIdentifier(): string
    {
        return $this->subscription->stripe_id.':'.$this->resumesAt->toDateString();
    }

    /**
     * Re-checked at send time: the user may have resumed already, cancelled,
     * or moved the end of the pause since the command queued this.
     */
    protected function shouldSend(): bool
    {
        $this->subscription->refresh();

        return $this->subscription->valid()
            && ! $this->subscription->canceled()
            && $this->subscription->hasPause()
            && $this->subscription->pause_resumes_at?->equalTo($this->resumesAt) === true;
    }
}
