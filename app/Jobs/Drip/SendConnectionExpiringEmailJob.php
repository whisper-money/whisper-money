<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\ConnectionExpiringEmail;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Mail\Mailable;

class SendConnectionExpiringEmailJob extends SendDripEmailJob
{
    public function __construct(User $user, public BankingConnection $bankingConnection)
    {
        parent::__construct($user);
    }

    protected function emailType(): DripEmailType
    {
        return DripEmailType::ConnectionExpiring;
    }

    protected function buildMail(): Mailable
    {
        return new ConnectionExpiringEmail($this->user, $this->bankingConnection);
    }

    /**
     * Keyed on the consent window rather than the type, so the user gets one
     * warning per window and a fresh one when the next window runs out. It is
     * also what makes the daily command idempotent: it re-dispatches this job
     * every day the connection is inside the warning window.
     */
    protected function emailIdentifier(): string
    {
        return $this->bankingConnection->id.':'.$this->bankingConnection->valid_until?->toDateString();
    }

    /**
     * Re-checked at send time: between the command queueing this and the queue
     * getting to it the user may have renewed the consent, which pushes
     * `valid_until` months out, or disconnected the bank altogether.
     */
    protected function shouldSend(): bool
    {
        return ! $this->bankingConnection->trashed()
            && $this->bankingConnection->isActive()
            && $this->bankingConnection->isExpiringSoon();
    }
}
