<?php

namespace App\Console\Commands;

use App\Enums\DunningStep;
use App\Jobs\Drip\SendPaymentActionRequiredEmailJob;
use App\Jobs\Drip\SendPaymentFailedEmailJob;
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
            ->where('first_failed_at', '<=', now()->subDays(DunningStep::Reminder->daysAfterFailure()))
            ->where('first_failed_at', '>', now()->subDays(self::GIVE_UP_AFTER_DAYS))
            ->whereHas('subscription', fn (Builder $subscription) => $subscription
                ->where('stripe_status', StripeSubscription::STATUS_PAST_DUE)
                ->whereNull('ends_at'))
            ->whereIn('user_id', User::query()->excludingSharedAccounts()->select('id'))
            ->with('user')
            ->chunkById(100, function (Collection $invoices) use (&$queued): void {
                foreach ($invoices as $invoice) {
                    /** @var FailedInvoice $invoice */
                    $step = DunningStep::reminderDueAfter((int) $invoice->first_failed_at->diffInDays(now()));

                    if ($step === null) {
                        continue;
                    }

                    // A payment waiting on the bank's confirmation is not
                    // fixed by a new card, so it is reminded to confirm.
                    dispatch($invoice->action_required_at !== null
                        ? new SendPaymentActionRequiredEmailJob($invoice->user, $invoice, $step)
                        : new SendPaymentFailedEmailJob($invoice->user, $invoice, $step));
                    $queued++;
                }
            });

        $this->info("Queued {$queued} payment-failed reminder(s).");

        return self::SUCCESS;
    }
}
