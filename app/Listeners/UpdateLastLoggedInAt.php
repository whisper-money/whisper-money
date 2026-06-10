<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

class UpdateLastLoggedInAt
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $user->forceFill([
            'last_logged_in_at' => $user->freshTimestamp(),
        ])->saveQuietly();
    }
}
