<?php

namespace App\Services\Dashboards;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What the system dashboard has to work with when it holds no real movement
 * yet, so it can say so instead of drawing empty spending and cashflow cards:
 *
 * - `estimates`: the only movements are the round figures typed in during the
 *   onboarding ({@see Transaction::scopeEstimates()}). The dashboard shows them,
 *   labelled as such, and asks for the real month in their place.
 * - `balances`: accounts with a balance and no account money is spent from
 *   (a fund, a pension, a property), and no movement at all. Spending and
 *   cashflow have nothing to read until a current account comes in.
 *
 * Null for everybody else: a user with one real movement, or with a current
 * account that is simply still empty, gets the dashboard as it always was.
 */
class FirstDashboardState
{
    /** The accounts spending is read from: what a "current account" means here. */
    private const SPENDING_ACCOUNT_TYPES = [AccountType::Checking, AccountType::CreditCard];

    /**
     * @return array{state: 'estimates'|'balances', today: string, tracking_since: string, accounts_count: int, spending_guess: int|null, currency_code: string, estimates?: array<string, mixed>, can_import?: bool}|null
     */
    public function for(User $user): ?array
    {
        if (! $this->isBeforeFirstMovement($user)) {
            return null;
        }

        $estimates = $this->latestEstimates($user);

        if ($estimates->isNotEmpty()) {
            return [
                'state' => 'estimates',
                ...$this->header($user),
                'estimates' => $this->presentEstimates($estimates),
            ];
        }

        if ($this->isBalanceOnly($user)) {
            return [
                'state' => 'balances',
                ...$this->header($user),
                // Whether a bank file has an account to go into: without one
                // the importer can only say to create it first, so the card
                // offers the account instead.
                'can_import' => $user->accounts()->get()->contains(fn (Account $account): bool => $account->acceptsManualMovements()),
            ];
        }

        return null;
    }

    /**
     * Whether the only movements are onboarding estimates: the state in which
     * the estimates card asks for the import, so the "Finish setting up" card
     * stays away and there is one nudge on screen.
     */
    public function isEstimateOnly(User $user): bool
    {
        return $this->isBeforeFirstMovement($user)
            && $user->transactions()->estimates()->exists();
    }

    /**
     * Onboarded, and nothing yet from a bank, a file or a manual entry.
     */
    private function isBeforeFirstMovement(User $user): bool
    {
        return $user->onboarded_at !== null
            && ! $user->transactions()->withoutEstimates()->exists();
    }

    /**
     * Accounts, none of them one spending happens in, and no movement at all
     * (estimates included: those are the other state).
     */
    private function isBalanceOnly(User $user): bool
    {
        if ($user->transactions()->exists()) {
            return false;
        }

        $types = $user->accounts()->notArchived()->pluck('type');

        return $types->isNotEmpty()
            && ! $types->contains(fn (AccountType $type): bool => in_array($type, self::SPENDING_ACCOUNT_TYPES, true));
    }

    /**
     * What the page header says under the title in both states: since when
     * the user is tracking (the day the onboarding ended), how many accounts,
     * and the spending guess they gave. Days are on the user's own calendar,
     * `today` included, so the page compares like with like.
     *
     * @return array{today: string, tracking_since: string, accounts_count: int, spending_guess: int|null, currency_code: string}
     */
    private function header(User $user): array
    {
        $guess = $user->onboarding_answers['spending_guess'] ?? null;

        return [
            'today' => $user->localToday()->toDateString(),
            'tracking_since' => $user->onboarded_at->copy()->setTimezone($user->localTimezone())->toDateString(),
            'accounts_count' => $user->accounts()->notArchived()->count(),
            'spending_guess' => is_int($guess) ? $guess : null,
            'currency_code' => (string) $user->currency_code,
        ];
    }

    /**
     * The estimates of the newest month that holds any, in the account the
     * newest of them is in: onboarding types one month into one account, and
     * a second go at it replaces the first. Kept to that one account so the
     * figures share its currency and the import link has one place to open.
     *
     * @return Collection<int, Transaction>
     */
    private function latestEstimates(User $user): Collection
    {
        $newest = $user->transactions()->estimates()->latest('transaction_date')->first(['account_id', 'transaction_date']);

        if ($newest === null) {
            return collect();
        }

        $month = $newest->transaction_date;

        return $user->transactions()
            ->estimates()
            ->where('account_id', $newest->account_id)
            ->whereBetween('transaction_date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->with(['category:id,name', 'account:id,banking_connection_id,type,archived_at'])
            ->get();
    }

    /**
     * The month the estimates stand for and each row as the user typed it,
     * largest first. Rows are named by their category, or by the description
     * the estimate was stored with when its category is gone.
     *
     * @param  Collection<int, Transaction>  $estimates
     * @return array{month: string, count: int, total: int, currency_code: string, account_id: string|null, rows: list<array{name: string, amount: int}>}
     */
    private function presentEstimates(Collection $estimates): array
    {
        $rows = $estimates
            ->groupBy(fn (Transaction $estimate): string => $estimate->category_id ?? 'description:'.$estimate->description)
            ->map(fn (Collection $group): array => [
                'name' => (string) ($group->first()->category->name ?? $group->first()->description),
                'amount' => (int) -$group->sum('amount'),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();

        $first = $estimates->first();
        $account = $first->account;

        return [
            'month' => $first->transaction_date->format('Y-m'),
            'count' => $estimates->count(),
            'total' => (int) collect($rows)->sum('amount'),
            'currency_code' => (string) $first->currency_code,
            // The account the import opens on, and the emailed link names,
            // when it can still take a file.
            'account_id' => $account?->acceptsManualMovements() ? $account->id : null,
            'rows' => $rows,
        ];
    }
}
