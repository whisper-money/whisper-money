<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * What the user actually has by the end of the onboarding.
 *
 * Steps 10 and 11 are both written from it and neither may invent anything:
 * the first target is built on real spending, and the closing screen lists what
 * exists rather than what the flow hoped the user would do.
 */
class OnboardingSummaryService
{
    public function __construct(private OnboardingRevealService $reveal) {}

    /**
     * @return array{
     *     currency_code: string,
     *     accounts: int,
     *     connected_accounts: int,
     *     transactions: int,
     *     months: int,
     *     rules: int,
     *     monthly_spending: ?int,
     *     recurring_count: int,
     *     recurring_amount: int,
     *     target: ?int,
     * }
     */
    public function for(User $user): array
    {
        $accounts = $user->accounts()->get(['id', 'banking_connection_id']);
        $ledger = $this->ledger($user, $accounts->pluck('id'));
        $spending = $this->spending($user);

        return [
            'currency_code' => $user->currency_code,
            'accounts' => $accounts->count(),
            'connected_accounts' => $accounts
                ->filter(fn (Account $account): bool => $account->banking_connection_id !== null)
                ->count(),
            'transactions' => $ledger['transactions'],
            'months' => $ledger['months'],
            // What sorts everything arriving from now on, which is the only
            // reason the closing screen may claim that it does.
            'rules' => $user->automationRules()->count(),
            'monthly_spending' => $spending['spent'] ?? null,
            'recurring_count' => $spending['recurring_count'] ?? 0,
            'recurring_amount' => $spending['recurring_amount'] ?? 0,
            'target' => $this->target($user),
        ];
    }

    /**
     * What is in the user's ledger: how much of it there is, and how far back
     * it goes.
     *
     * Shared with the sync step, which asks the same of the accounts a single
     * connection is still filling — a spinner cannot tell a slow bank from a
     * dead queue, and counters that move can.
     *
     * @param  Collection<int, string>  $accountIds
     * @return array{transactions: int, merchants: int, accounts: int, months: int, first_date: ?string, last_date: ?string}
     */
    public function ledger(User $user, Collection $accountIds): array
    {
        $empty = [
            'transactions' => 0,
            'merchants' => 0,
            'accounts' => $accountIds->count(),
            'months' => 0,
            'first_date' => null,
            'last_date' => null,
        ];

        if ($accountIds->isEmpty()) {
            return $empty;
        }

        // `toBase()`: the row is four aggregates, not a transaction, so it is
        // read as the plain result row it is rather than hydrated into a
        // Transaction that has none of these columns.
        $totals = Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('account_id', $accountIds)
            ->selectRaw('count(*) as transactions, count(distinct creditor_name) as merchants, min(transaction_date) as first_date, max(transaction_date) as last_date')
            ->toBase()
            ->first();

        if (! $totals || (int) $totals->transactions === 0) {
            return $empty;
        }

        $first = Carbon::parse($totals->first_date)->startOfMonth();
        $last = Carbon::parse($totals->last_date)->startOfMonth();

        return [
            'transactions' => (int) $totals->transactions,
            // Counted off `creditor_name`, the one column that actually names a
            // counterparty; `description` is free-form bank text, not a merchant.
            'merchants' => (int) $totals->merchants,
            'accounts' => $accountIds->count(),
            // Inclusive: a January-to-January import covers one month, not zero.
            'months' => (int) $first->diffInMonths($last) + 1,
            'first_date' => $first->toDateString(),
            'last_date' => $last->toDateString(),
        ];
    }

    /**
     * The month step 7 was written about, for the two screens built on it and
     * for the endpoint that turns the first of them into a budget.
     *
     * Null for the user who brought a pension and a mortgage and no current
     * account: they have no spending, so they get no target screen either.
     *
     * @return array<string, mixed>|null
     */
    public function spending(User $user): ?array
    {
        $reveal = $this->reveal->for($user);

        return $reveal['variant'] === 'spending' ? $reveal : null;
    }

    /**
     * The monthly target the user set, if they set one.
     *
     * Read back from the answers rather than derived from the budget's limit:
     * the limit moves with whatever the ledger says was spent, and the number
     * the closing screen reports is the one the user chose.
     */
    private function target(User $user): ?int
    {
        $target = $user->onboarding_answers['target'] ?? null;

        return is_int($target) ? $target : null;
    }
}
