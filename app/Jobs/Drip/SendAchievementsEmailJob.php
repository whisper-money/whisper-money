<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\AchievementsEmail;
use App\Models\Achievement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;

/**
 * The one email a sweep can produce.
 *
 * Keyed on the day, so however many times a sweep is re-run — a retry, a manual
 * pass, a second look at one reader — the medals of that day are announced once.
 * The ids travel rather than the models, so a row deleted between the sweep and
 * the send simply drops out of the message.
 */
class SendAchievementsEmailJob extends SendDripEmailJob
{
    /**
     * @param  list<string>  $achievementIds
     */
    public function __construct(User $user, public array $achievementIds)
    {
        parent::__construct($user);
    }

    protected function emailType(): DripEmailType
    {
        return DripEmailType::AchievementsUnlocked;
    }

    protected function emailIdentifier(): string
    {
        return now()->toDateString();
    }

    protected function shouldSend(): bool
    {
        return $this->user->wantsAchievementsEmail() && $this->achievements()->isNotEmpty();
    }

    protected function buildMail(): Mailable
    {
        return new AchievementsEmail($this->user, $this->achievements());
    }

    /**
     * @return Collection<int, Achievement>
     */
    private function achievements()
    {
        return $this->user->achievements()
            ->whereIn('id', $this->achievementIds)
            ->orderBy('achieved_on')
            ->get();
    }
}
