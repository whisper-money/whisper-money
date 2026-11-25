<?php

namespace App\Services\Dashboards;

use App\Enums\AccountType;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
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
     * @return array<string, mixed>|null
     */
    public function for(User $user): ?array
    {
        if ($user->onboarded_at === null || $this->hasRealMovements($user)) {
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
        return $user->onboarded_at !== null
            && ! $this->hasRealMovements($user)
            && $user->transactions()->estimates()->exists();
    }

    private function hasRealMovements(User $user): bool
    {
        return $user->transactions()->withoutEstimates()->exists();
    }

    /**
     * Accounts, none of them one spending happens in, and no movement at all.
     */
    private function isBalanceOnly(User $user): bool
    {
        $types = $user->accounts()->notArchived()->pluck('type');

        return $types->isNotEmpty()
            && ! $types->contains(fn (AccountType $type): bool => in_array($type, self::SPENDING_ACCOUNT_TYPES, true))
            && ! $user->transactions()->exists();
    }

    /**
     * What the page header says under the title in both states: since when
     * the user is tracking (the day the onboarding ended, on their own
     * calendar), how many accounts, and the spending guess they gave.
     *
     * @return array{tracking_since: string, accounts_count: int, spending_guess: int|null, currency_code: string}
     */
    private function header(User $user): array
    {
        $guess = $user->onboarding_answers['spending_guess'] ?? null;

        return [
            'tracking_since' => $user->onboarded_at->copy()->setTimezone($user->localTimezone())->toDateString(),
            'accounts_count' => $user->accounts()->notArchived()->count(),
            'spending_guess' => is_int($guess) ? $guess : null,
            'currency_code' => (string) $user->currency_code,
        ];
    }

    /**
     * The estimates of the newest month that holds any: onboarding types in
     * one month, and a second go at it replaces the first.
     *
     * @return Collection<int, Transaction>
     */
    private function latestEstimates(User $user): Collection
    {
        $latest = $user->transactions()->estimates()->max('transaction_date');

        if ($latest === null) {
            return collect();
        }

        $month = Carbon::parse($latest);

        return $user->transactions()
            ->estimates()
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
            ->groupBy(fn (Transaction $estimate): string => $estimate->category?->name ?? $estimate->description)
            ->map(fn (Collection $group, string $name): array => [
                'name' => $name,
                'amount' => (int) -$group->sum('amount'),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();

        $account = $estimates->first()->account;

        return [
            'month' => $estimates->first()->transaction_date->format('Y-m'),
            'count' => $estimates->count(),
            'total' => (int) collect($rows)->sum('amount'),
            'currency_code' => (string) $estimates->first()->currency_code,
            // The account the emailed import link opens on, when it can take
            // the file: the one the estimates were typed into.
            'account_id' => $account?->acceptsManualMovements() ? $account->id : null,
            'rows' => $rows,
        ];
    }
}
