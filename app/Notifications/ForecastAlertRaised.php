<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Rings the bell when the forecast finds a new month where one of the user's
 * accounts could run short. Database only, and like the email it keeps no
 * amount and no account: the forecast page has those.
 */
class ForecastAlertRaised extends Notification
{
    /**
     * @param  string  $month  `Y-m`, the earliest month short.
     * @param  string  $signature  The alerts it was rung for, so a retry does not ring twice.
     */
    public function __construct(
        public string $month,
        public string $signature,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{month: string, signature: string}
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'month' => $this->month,
            'signature' => $this->signature,
        ];
    }
}
