<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * The one row a reader gets when the medals first arrive.
 *
 * The first sweep reads a whole financial life at once and can unlock twenty
 * medals in a second. Twenty rows would be an avalanche and none of them would
 * be read, so the backfill is silent and this says how it went, once.
 */
class AchievementsWelcome extends Notification
{
    public function __construct(public int $count) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return ['count' => $this->count];
    }
}
