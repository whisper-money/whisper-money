<?php

namespace App\Enums;

/**
 * Where a failed subscription payment is in our dunning sequence: the email
 * sent when the charge first fails, and the reminders sent while Stripe's
 * Smart Retries keep trying (8 attempts over 3 weeks).
 *
 * The reminders go by days since the first failure rather than by Stripe's
 * retry dates: Smart Retries picks each attempt's time on its own and the
 * invoice only ever says when the next one is, never which one is the last
 * (`next_payment_attempt` turns null only once the last one has failed, too
 * late for a "last notice"). Day 14 leaves a week of retries after the last
 * notice. The value doubles as the mail log identifier suffix and the
 * `utm_content` of the email's links.
 */
enum DunningStep: string
{
    case First = 'first';
    case Reminder = 'day_3';
    case SecondReminder = 'day_7';
    case FinalNotice = 'final';

    public function daysAfterFailure(): int
    {
        return match ($this) {
            self::First => 0,
            self::Reminder => 3,
            self::SecondReminder => 7,
            self::FinalNotice => 14,
        };
    }

    public function isReminder(): bool
    {
        return $this !== self::First;
    }

    /**
     * The latest reminder due so many days after the first failure, or null
     * before the first one. Only the latest: a run that was missed does not
     * make the reader catch up on two emails at once.
     */
    public static function reminderDueAfter(int $days): ?self
    {
        $due = array_filter(
            self::cases(),
            fn (self $step): bool => $step->isReminder() && $step->daysAfterFailure() <= $days,
        );

        return $due === [] ? null : end($due);
    }
}
