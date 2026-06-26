<?php

namespace App\Console\Commands;

use App\Jobs\Drip\SendAiConsentFollowUpEmailJob;
use App\Models\AiConsent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class SendAiConsentFollowUpEmailsCommand extends Command
{
    protected $signature = 'email:ai-consent-follow-up';

    protected $description = 'Queue the AI consent follow-up email for users who opted into AI two days ago, outside of onboarding';

    /**
     * Consent given more than this many days after sign-up is treated as a
     * deliberate, post-onboarding opt-in (the audience for this email).
     */
    private const ONBOARDING_GRACE_DAYS = 3;

    public function handle(): int
    {
        if (! config('mail.drip_emails_enabled')) {
            $this->info('Drip emails are disabled. Nothing to do.');

            return self::SUCCESS;
        }

        $queued = 0;

        AiConsent::query()
            ->active()
            ->whereDate('accepted_at', today()->subDays(2))
            ->with('user')
            ->chunkById(100, function (Collection $consents) use (&$queued): void {
                foreach ($consents as $consent) {
                    $user = $consent->user;

                    if ($user === null) {
                        continue;
                    }

                    if ($consent->accepted_at->lessThanOrEqualTo($user->created_at->copy()->addDays(self::ONBOARDING_GRACE_DAYS))) {
                        continue;
                    }

                    SendAiConsentFollowUpEmailJob::dispatch($user);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} AI consent follow-up email(s).");

        return self::SUCCESS;
    }
}
