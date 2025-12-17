<?php

namespace App\Console\Commands;

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendFeedbackEmailJob;
use App\Jobs\Drip\SendImportHelpEmailJob;
use App\Jobs\Drip\SendOnboardingReminderEmailJob;
use App\Jobs\Drip\SendPromoCodeEmailJob;
use App\Jobs\Drip\SendWelcomeEmailJob;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Console\Command;

class TestScheduledDripEmailCommand extends Command
{
    protected $signature = 'email:test-scheduled-drip {email} {--force : Delete existing mail logs for this user}';

    protected $description = 'Test the scheduled drip email campaign for a specific user';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("User with email '{$email}' not found.");

            return self::FAILURE;
        }

        if ($this->option('force')) {
            $deleted = UserMailLog::query()
                ->where('user_id', $user->id)
                ->whereIn('email_type', [
                    DripEmailType::Welcome,
                    DripEmailType::OnboardingReminder,
                    DripEmailType::PromoCode,
                    DripEmailType::ImportHelp,
                    DripEmailType::Feedback,
                ])
                ->delete();

            if ($deleted > 0) {
                $this->info("Deleted {$deleted} mail log(s) for user '{$email}'.");
            }
        }

        $this->info("Scheduling drip emails for user '{$email}'...");

        SendWelcomeEmailJob::dispatch($user)->delay(now()->addMinutes(30));
        SendOnboardingReminderEmailJob::dispatch($user)->delay(now()->addDay());
        SendPromoCodeEmailJob::dispatch($user)->delay(now()->addDay());
        SendImportHelpEmailJob::dispatch($user)->delay(now()->addDay());
        SendFeedbackEmailJob::dispatch($user)->delay(now()->addDays(5));

        $this->info('Drip emails have been scheduled successfully!');
        $this->newLine();
        $this->table(
            ['Email Type', 'Scheduled For'],
            [
                ['Welcome', now()->addMinutes(30)->format('Y-m-d H:i:s')],
                ['Onboarding Reminder', now()->addDay()->format('Y-m-d H:i:s')],
                ['Promo Code', now()->addDay()->format('Y-m-d H:i:s')],
                ['Import Help', now()->addDay()->format('Y-m-d H:i:s')],
                ['Feedback', now()->addDays(5)->format('Y-m-d H:i:s')],
            ]
        );

        return self::SUCCESS;
    }
}
