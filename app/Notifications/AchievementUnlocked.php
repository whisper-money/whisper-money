<?php

namespace App\Notifications;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * One medal, one row in the bell.
 *
 * Database only. The email that follows a sweep is one message for the whole
 * day's medals and is sent separately, so three medals on a Tuesday are three
 * lines in the bell and one message in the inbox.
 *
 * The row stores the medal's id and the figure that earned it; the name, the
 * milestone and the tier are read from the catalog when the row is drawn, so a
 * reworded medal reads its new wording everywhere.
 */
class AchievementUnlocked extends Notification
{
    public function __construct(public Achievement $achievement) {}

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
        return [
            'achievement_id' => $this->achievement->id,
            'key' => $this->achievement->key,
            'achieved_on' => $this->achievement->achieved_on->toDateString(),
            'value' => $this->achievement->value,
            'percent' => $this->achievement->percent,
            'currency_code' => $this->achievement->currency_code,
        ];
    }
}
