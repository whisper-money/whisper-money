<?php

namespace App\Listeners;

use App\Jobs\Drip\SendFeedbackEmailJob;
use App\Jobs\Drip\SendImportHelpEmailJob;
use App\Jobs\Drip\SendOnboardingReminderEmailJob;
use App\Jobs\Drip\SendPromoCodeEmailJob;
use App\Jobs\Drip\SendWelcomeEmailJob;
use Illuminate\Auth\Events\Registered;

class ScheduleDripEmailsListener
{
    public function handle(Registered $event): void
    {
        if (! config('mail.drip_emails_enabled')) {
            return;
        }

        $user = $event->user;

        SendWelcomeEmailJob::dispatch($user)->delay(now()->addMinutes(30));
        SendOnboardingReminderEmailJob::dispatch($user)->delay(now()->addDay());
        SendPromoCodeEmailJob::dispatch($user)->delay(now()->addDay());
        SendImportHelpEmailJob::dispatch($user)->delay(now()->addDay());
        SendFeedbackEmailJob::dispatch($user)->delay(now()->addDays(5));
    }
}
