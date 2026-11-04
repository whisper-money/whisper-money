<?php

namespace App\Listeners;

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendSubscriptionCancelledEmailJob;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Events\WebhookHandled;
use Throwable;

/**
 * Queues the "sorry to see you go" email when Stripe reports a subscription
 * has ended (`customer.subscription.deleted`): a trial cancelled before the
 * first charge, or a paid plan reaching the end of its cancelled period.
 *
 * It listens to {@see WebhookHandled} rather than `WebhookReceived` (which
 * {@see SendTrialEndingEmail} uses) so Cashier has already marked the local
 * subscription as ended by the time we look at it. The job is delayed and
 * re-checks everything when it runs, so a user who subscribes again straight
 * away, or switches plan through a fresh checkout, is never told they left.
 * Which of the two emails goes out, and who is never written to, live in the
 * job so the manual `email:subscription-cancelled` command gets them too.
 */
class SendSubscriptionCancelledEmail
{
    private const DELAY_MINUTES = 60;

    /**
     * Long enough to outlive the delayed job, short enough that a user who
     * resubscribes and cancels again later is not swallowed by a stale claim.
     */
    private const DEDUPE_TTL_MINUTES = self::DELAY_MINUTES + 60;

    public function handle(WebhookHandled $event): void
    {
        if (($event->payload['type'] ?? null) !== 'customer.subscription.deleted') {
            return;
        }

        if (! config('mail.drip_emails_enabled')) {
            return;
        }

        $user = $this->customer($event->payload['data']['object']['customer'] ?? null);

        if ($user === null || $user->hasReceivedEmail(DripEmailType::SubscriptionCancelled)) {
            return;
        }

        // Keyed on the user, not the event: the mail log is the durable
        // once-ever guard, this only stops a redelivery (or two subscriptions
        // ending together) queueing a second job before the first has run.
        $claim = 'subscription-cancelled:user:'.$user->id;

        if (! Cache::add($claim, true, now()->addMinutes(self::DEDUPE_TTL_MINUTES))) {
            return;
        }

        try {
            Bus::dispatch(
                (new SendSubscriptionCancelledEmailJob($user))->delay(now()->addMinutes(self::DELAY_MINUTES)),
            );
        } catch (Throwable $exception) {
            Cache::forget($claim);

            throw $exception;
        }
    }

    private function customer(mixed $customerId): ?User
    {
        if (! is_string($customerId) || $customerId === '') {
            return null;
        }

        return User::query()->where('stripe_id', $customerId)->first();
    }
}
