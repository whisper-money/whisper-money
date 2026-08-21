<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\InactiveNoBankEmail;
use App\Models\User;
use Illuminate\Mail\Mailable;

class SendInactiveNoBankEmailJob extends SendDripEmailJob
{
    /**
     * @param  string  $dormantOn  The Y-m-d the user was last active, i.e. the
     *                             dormancy episode this email belongs to.
     */
    public function __construct(User $user, public string $dormantOn)
    {
        parent::__construct($user);
    }

    protected function emailType(): DripEmailType
    {
        return DripEmailType::InactiveNoBank;
    }

    protected function buildMail(): Mailable
    {
        return new InactiveNoBankEmail($this->user);
    }

    /**
     * Keyed on the episode rather than the type, so the user gets one email per
     * dormancy episode instead of one ever.
     */
    protected function emailIdentifier(): string
    {
        return $this->dormantOn;
    }

    protected function shouldSend(): bool
    {
        return $this->user->last_active_at?->toDateString() === $this->dormantOn
            && $this->user->wantsInactiveNoBankEmail()
            && ! $this->user->bankingConnections()->exists();
    }
}
