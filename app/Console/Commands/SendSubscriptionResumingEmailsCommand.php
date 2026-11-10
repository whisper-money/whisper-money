<?php

namespace App\Console\Commands;

use App\Jobs\Drip\SendSubscriptionResumingEmailJob;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\SubscribedPlan;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Stripe\Subscription as StripeSubscription;

class SendSubscriptionResumingEmailsCommand extends Command
{
    /** How long before a pause ends the user hears about the charge. */
    private const WARNING_DAYS = 3;

    protected $signature = 'email:subscription-resuming';

    protected $description = 'Warn users whose paused plan resumes within three days, with the date and the amount charged';

    public function handle(SubscribedPlan $subscribedPlan): int
    {
        if (! config('mail.drip_emails_enabled')) {
            $this->info('Drip emails are disabled. Nothing to do.');

            return self::SUCCESS;
        }

        $queued = 0;

        // The whole window rather than the one day exactly three days out, so
        // a missed run never costs the warning: the job keys its mail log on
        // the pause and drops the repeats. A plan set to cancel is left out,
        // as nothing will be charged.
        Subscription::query()
            ->where('type', 'default')
            ->whereNull('ends_at')
            ->where('stripe_status', StripeSubscription::STATUS_ACTIVE)
            ->whereNotNull('pause_starts_at')
            ->where('pause_resumes_at', '>', now())
            ->where('pause_resumes_at', '<=', now()->addDays(self::WARNING_DAYS))
            ->whereIn('user_id', User::query()->excludingSharedAccounts()->select('id'))
            ->with('owner')
            ->chunkById(100, function (Collection $subscriptions) use ($subscribedPlan, &$queued): void {
                foreach ($subscriptions as $subscription) {
                    /** @var Subscription $subscription */
                    /** @var User $user */
                    $user = $subscription->owner;
                    // The amount we quote is the price of the plan they pay,
                    // so a plan we cannot match gets no email rather than a
                    // wrong figure.
                    $plan = $subscribedPlan->for($user, $subscription);

                    if ($plan === null) {
                        continue;
                    }

                    SendSubscriptionResumingEmailJob::dispatch(
                        $user,
                        $subscription,
                        $subscription->pause_resumes_at->toImmutable(),
                        (int) round($plan['price'] * 100),
                        (string) config('cashier.currency', 'eur'),
                    );
                    $queued++;
                }
            });

        $this->info("Queued {$queued} subscription-resuming email(s).");

        return self::SUCCESS;
    }
}
