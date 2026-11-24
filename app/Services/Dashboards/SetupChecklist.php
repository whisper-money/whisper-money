<?php

namespace App\Services\Dashboards;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OnboardingSummaryService;
use App\Services\Subscriptions\UserSetupStats;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The "Finish setting up" card at the top of the system dashboard: the few
 * steps that turn what onboarding left behind into a dashboard worth reading,
 * each one done or still to do.
 *
 * Only the steps that apply are listed: no "categorize" for a user with no
 * movements, no "add a balance" when every account has one. The card is for
 * the first days after onboarding, goes away once every step is done, and the
 * user can hide it for good.
 *
 * While it is on screen it is the one nudge: {@see self::takesOverUncategorizedPrompt()}
 * keeps the "N uncategorized transactions" toast quiet, since the card already
 * asks for the same work with the same action.
 *
 * Not {@see UserSetupStats}: that one counts what a
 * user would lose for the paywall and the trial emails, five totals with no
 * notion of "done". This answers different questions (which accounts have no
 * balance, which limit was set, over what months the movements run).
 */
class SetupChecklist
{
    /**
     * How long after onboarding the card is offered. Long enough to come back
     * to the dashboard a few times, short enough that it never becomes part
     * of the furniture.
     */
    public const WINDOW_DAYS = 14;

    public function __construct(private OnboardingSummaryService $onboarding) {}

    /**
     * The card's steps in order, or null when there is no card to draw: the
     * window is over, the user hid it, or every step is done.
     *
     * @return array{steps: list<array<string, mixed>>}|null
     */
    public function for(User $user): ?array
    {
        if (! $this->isOpenFor($user)) {
            return null;
        }

        $imported = $this->imported($user);
        $hasMovements = $imported !== null;

        $steps = array_values(array_filter([
            $imported,
            $this->spendingLimit($user, $hasMovements),
            $hasMovements ? $this->categorize($user) : null,
            $this->balance($user),
        ]));

        if (collect($steps)->every(fn (array $step): bool => $step['done'])) {
            return null;
        }

        return ['steps' => $steps];
    }

    /**
     * Whether the card has the uncategorized movements in hand, so the toast
     * asking for them stays quiet: the card is still offered and there is
     * something left to categorize. Columns on the user's row first, so the
     * query only runs in the first days after onboarding.
     */
    public function takesOverUncategorizedPrompt(User $user): bool
    {
        return $this->isOpenFor($user) && $this->uncategorized($user)->exists();
    }

    public function dismiss(User $user): void
    {
        $user->dashboard_setup_dismissed_at = now();
        $user->save();
    }

    private function isOpenFor(User $user): bool
    {
        return $user->onboarded_at !== null
            && $user->onboarded_at->gt(now()->subDays(self::WINDOW_DAYS))
            && $user->dashboard_setup_dismissed_at === null;
    }

    /**
     * The movements that came from a bank, a file or a manual entry, with the
     * months they run over and the account they landed in. Null when there
     * are none: an estimate-only dashboard is a state of its own.
     *
     * @return array{key: string, done: bool, count: int, first_date: string, last_date: string, accounts_count: int, account_name: string|null}|null
     */
    private function imported(User $user): ?array
    {
        $totals = $user->transactions()
            ->withoutEstimates()
            ->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('MIN(transaction_date) as first_date')
            ->selectRaw('MAX(transaction_date) as last_date')
            ->selectRaw('COUNT(DISTINCT account_id) as accounts_count')
            ->selectRaw('MIN(account_id) as account_id')
            ->first();

        if ((int) $totals->total === 0) {
            return null;
        }

        $accountsCount = (int) $totals->accounts_count;

        return [
            'key' => 'imported',
            'done' => true,
            'count' => (int) $totals->total,
            'first_date' => CarbonImmutable::parse($totals->first_date)->toDateString(),
            'last_date' => CarbonImmutable::parse($totals->last_date)->toDateString(),
            'accounts_count' => $accountsCount,
            // Named only when there is one: "Jun – Nov, BBVA" reads better than
            // "1 account", and a list of several names does not fit the tile.
            'account_name' => $accountsCount === 1
                ? Account::query()->withTrashed()->whereKey($totals->account_id)->value('name')
                : null,
        ];
    }

    /**
     * The monthly limit onboarding turns the target into (the catch-all
     * budget), with how far under the month it was built on the user set it.
     * A user without movements has nothing to limit yet, so the step only
     * shows for them once a limit exists.
     *
     * @return array{key: string, done: bool, limit: int|null, under_last_month: int|null, currency_code: string}|null
     */
    private function spendingLimit(User $user, bool $hasMovements): ?array
    {
        $budget = $this->onboarding->catchAll($user);

        if ($budget === null && ! $hasMovements) {
            return null;
        }

        $target = $user->onboarding_answers['target'] ?? null;

        return [
            'key' => 'spending_limit',
            'done' => $budget !== null,
            'limit' => $budget?->getCurrentPeriod()?->allocated_amount,
            'under_last_month' => $budget !== null && is_int($target) ? $target : null,
            'currency_code' => (string) $user->currency_code,
        ];
    }

    /**
     * Every real movement still without a category, not only this month's:
     * the card is about the history just brought in.
     *
     * @return array{key: string, done: bool, count: int}
     */
    private function categorize(User $user): array
    {
        $count = $this->uncategorized($user)->count();

        return [
            'key' => 'categorize',
            'done' => $count === 0,
            'count' => $count,
        ];
    }

    /**
     * The first account that never got a balance (a loan added without what
     * is still owed, a property without its value), and how many more are
     * like it. Accounts fed by a bank get theirs from the sync, and a credit
     * card has a limit rather than a balance, so neither is asked about.
     *
     * @return array{key: string, done: bool, account: array<string, mixed>, others_count: int}|null
     */
    private function balance(User $user): ?array
    {
        $accounts = $user->accounts()
            ->notArchived()
            ->whereNull('banking_connection_id')
            ->where('type', '!=', AccountType::CreditCard)
            ->whereDoesntHave('balances')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        $account = $accounts->first();

        if ($account === null) {
            return null;
        }

        return [
            'key' => 'balance',
            'done' => false,
            // What the balance dialog needs to open on this account.
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'currency_code' => $account->currency_code,
                'bank' => null,
                'banking_connection_id' => null,
                'external_account_id' => null,
                'linked_at' => null,
                'ownership_percentage' => $account->ownership_percentage,
                'ownership_applies_to_balance' => $account->ownership_applies_to_balance,
            ],
            'others_count' => $accounts->count() - 1,
        ];
    }

    /**
     * @return HasMany<Transaction, User>
     */
    private function uncategorized(User $user): HasMany
    {
        return $user->transactions()->withoutEstimates()->whereNull('category_id');
    }
}
