<?php

namespace App\Console\Commands;

use App\Enums\DunningStep;
use App\Jobs\Drip\SendDunningEmailJob;
use App\Models\FailedInvoice;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Stripe\Subscription as StripeSubscription;

class SendPaymentFailedRemindersCommand extends Command
{
    /**
     * Stripe gives up after three weeks; a row still open well past that
     * missed the webhook that closed it and is not worth an email.
     */
    private const GIVE_UP_AFTER_DAYS = 30;

    protected $signature = 'email:payment-failed-reminders';

    protected $description = 'Remind users whose subscription payment failed, on days 3, 7 and 14 of Stripe\'s retries';

    public function handle(): int
    {
        if (! config('mail.drip_emails_enabled')) {
            $this->info('Drip emails are disabled. Nothing to do.');

            return self::SUCCESS;
        }

        $queued = 0;

        // Everything comes from the rows the webhooks keep up to date, so the
        // run never asks Stripe. The job checks the invoice and subscription
        // again and drops a step it already sent.
        FailedInvoice::query()
            ->open()
            ->where('first_failed_at', '<', today()->subDays(DunningStep::Reminder->daysAfterFailure() - 1))
            ->where('first_failed_at', '>', now()->subDays(self::GIVE_UP_AFTER_DAYS))
            ->whereHas('subscription', fn (Builder $subscription) => $subscription
                ->where('stripe_status', StripeSubscription::STATUS_PAST_DUE)
                ->whereNull('ends_at'))
            ->whereIn('user_id', User::query()->excludingSharedAccounts()->select('id'))
            ->with('user')
            ->chunkById(100, function (Collection $invoices) use (&$queued): void {
                foreach ($invoices as $invoice) {
                    /** @var FailedInvoice $invoice */
                    // Calendar days, so a failure late in the day is not
                    // reminded a day later than one in the morning.
                    $step = DunningStep::reminderDueAfter((int) $invoice->first_failed_at->copy()->startOfDay()->diffInDays(today()));

                    if ($step === null) {
                        continue;
                    }

                    dispatch(SendDunningEmailJob::for($invoice, $step));
                    $queued++;
                }
            });

        $this->info("Queued {$queued} payment-failed reminder(s).");

        return self::SUCCESS;
    }
}
