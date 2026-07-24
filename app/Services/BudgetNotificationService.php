<?php

namespace App\Services;

use App\Enums\BudgetNotificationType;
use App\Mail\BudgetNotificationEmail;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class BudgetNotificationService
{
    /**
     * Share of the limit that counts as "close to limit".
     *
     * ponytail: fixed 90%; make it a per-budget setting only if users ask.
     */
    private const CLOSE_TO_LIMIT_THRESHOLD = 0.9;

    /**
     * React to a transaction being (re)assigned to its matching budget periods.
     *
     * @param  array<int, string>  $matchingPeriodIds  every period the transaction now belongs to
     * @param  array<int, string>  $createdPeriodIds  periods where the assignment was newly created
     */
    public function handleAssignment(Transaction $transaction, array $matchingPeriodIds, array $createdPeriodIds): void
    {
        $user = $transaction->user;

        if (! $user || ! $user->canReceiveEmails() || $matchingPeriodIds === []) {
            return;
        }

        // Only the current period of each touched budget is relevant: the emails
        // describe the live "available before the limit" state, which is
        // meaningless for a period that has already ended.
        $periods = BudgetPeriod::query()
            ->whereIn('id', $matchingPeriodIds)
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today())
            ->with('budget')
            ->get();

        foreach ($periods as $period) {
            if (! $period->budget) {
                continue;
            }

            $this->processPeriod(
                $user,
                $transaction,
                $period,
                isNewlyAssigned: in_array($period->id, $createdPeriodIds, true),
            );
        }
    }

    private function processPeriod(User $user, Transaction $transaction, BudgetPeriod $period, bool $isNewlyAssigned): void
    {
        $budget = $period->budget;

        if ($isNewlyAssigned && $budget->notify_on_new_transaction) {
            $this->send($user, $period, BudgetNotificationType::NewTransaction, $transaction);
        }

        $allocated = $period->allocated_amount;

        // A budget with no limit can't be "close" or "over" in any useful way.
        if ($allocated <= 0) {
            return;
        }

        $ratio = $period->spentAmount() / $allocated;

        if ($ratio >= 1.0) {
            // Claim the over-limit slot atomically so concurrent workers can't
            // both send. Also claim "close" so a later dip into the close range
            // doesn't raise a second, lower-severity alarm for the same period.
            if ($budget->notify_on_over_limit && $this->claim($period, ['over_limit_notified', 'close_to_limit_notified'])) {
                $this->send($user, $period, BudgetNotificationType::OverLimit);
            }

            return;
        }

        if ($ratio >= self::CLOSE_TO_LIMIT_THRESHOLD) {
            if ($budget->notify_on_close_to_limit && $this->claim($period, ['close_to_limit_notified'])) {
                $this->send($user, $period, BudgetNotificationType::CloseToLimit);
            }

            return;
        }

        // Dropped back below both thresholds (e.g. a refund) — allow the next
        // crossing to notify again.
        BudgetPeriod::query()
            ->whereKey($period->id)
            ->where(fn ($query) => $query->where('close_to_limit_notified', true)->orWhere('over_limit_notified', true))
            ->update(['close_to_limit_notified' => false, 'over_limit_notified' => false]);
    }

    /**
     * Atomically flip the given "notified" flags from false to true, returning
     * true only for the worker that won the race (so exactly one email is sent).
     *
     * @param  array<int, string>  $flags
     */
    private function claim(BudgetPeriod $period, array $flags): bool
    {
        $primaryFlag = $flags[0];

        $claimed = BudgetPeriod::query()
            ->whereKey($period->id)
            ->where($primaryFlag, false)
            ->update(array_fill_keys($flags, true));

        return $claimed === 1;
    }

    private function send(User $user, BudgetPeriod $period, BudgetNotificationType $type, ?Transaction $transaction = null): void
    {
        Mail::to($user)->send(new BudgetNotificationEmail(
            $user,
            $period->budget,
            $period,
            $type,
            $transaction,
        ));
    }
}
