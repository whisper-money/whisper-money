<?php

namespace App\Jobs\Drip;

use App\Models\User;

/**
 * A drip email for one dormancy episode: keyed on the day the user was last
 * active, and dropped if they came back between the queueing and the send.
 */
abstract class SendInactiveUserEmailJob extends SendDripEmailJob
{
    /**
     * @param  string  $dormantOn  The Y-m-d the user was last active, i.e. the
     *                             dormancy episode this email belongs to.
     */
    public function __construct(User $user, public string $dormantOn)
    {
        parent::__construct($user);
    }

    /**
     * Keyed on the episode rather than the type, so the user gets one email per
     * dormancy episode instead of one ever.
     */
    protected function emailIdentifier(): string
    {
        return $this->dormantOn;
    }

    protected function isStillDormant(): bool
    {
        return $this->user->last_active_at?->toDateString() === $this->dormantOn;
    }
}
