<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\RuleSuggestionAggregator;
use App\Services\Concerns\ConvertsTransactionCurrency;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The answer to the question step 4 made the user commit to: what they actually
 * spent, against what they guessed they had.
 *
 * Nothing is categorized when this runs — step 8 writes rules and the batch
 * follows it — so there is no breakdown by category to draw, however tempting.
 * There are merchants: the name is on the raw row already and needs no model at
 * all, and "€312 at Mercadona" lands harder than "Groceries €312" anyway.
 */
class OnboardingRevealService
{
    use ConvertsTransactionCurrency;

    /** How far back a month worth revealing may be found. */
    private const MONTHS_READ = 12;

    /** Under this, a month's total is an anecdote rather than a month. */
    private const MIN_TRANSACTIONS = 5;

    /** How many merchants the screen names before the rest become a count. */
    private const TOP_MERCHANTS = 3;

    /** Months a charge has to repeat in, unchanged, to count as recurring. */
    private const RECURRING_MONTHS = 3;

    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private NetWorthCalculator $netWorth,
    ) {}

    /**
     * What this user has to be shown, which is not the same thing for everyone:
     * someone who only added a pension and a mortgage has no spending to reveal.
     *
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        return $this->spending($user) ?? $this->assets($user);
    }

    /**
     * The month the reveal is written about, or null when no month holds enough
     * movements for the number to mean anything.
     *
     * It is not necessarily the month before this one: a file exported months
     * ago is still a real import, and answering it with an empty month would be
     * worse than answering it a month late.
     *
     * @return array<string, mixed>|null
     */
    private function spending(User $user): ?array
    {
        $outgoings = $this->recentOutgoings($user);
        $byMonth = $outgoings->groupBy(fn (Transaction $transaction): string => $transaction->transaction_date->format('Y-m'));

        $month = $byMonth->keys()
            ->sortDesc()
            ->first(fn (string $month): bool => $byMonth[$month]->count() >= self::MIN_TRANSACTIONS);

        if ($month === null) {
            return null;
        }

        $currency = $user->currency_code;
        $this->preloadExchangeRates($outgoings, $currency);

        return [
            'variant' => 'spending',
            'currency_code' => $currency,
            'month' => $month,
            'is_last_month' => $month === now()->startOfMonth()->subMonth()->format('Y-m'),
            // Outgoings are stored negative and the screen states a spend, so
            // the sign is flipped once, here.
            'spent' => -$this->sumConvertedAmounts($byMonth[$month], $currency),
            'merchants' => $this->topMerchants($byMonth[$month], $currency),
            'merchant_count' => $this->merchantCount($user),
            'recurring_count' => $this->recurringMerchants($byMonth, $month),
        ];
    }

    /**
     * Every movement that took money out, over the window a month can be picked
     * from. Loaded rather than aggregated in SQL so each amount goes through the
     * same conversion and ownership weighting every other total in the app does.
     *
     * @return Collection<int, Transaction>
     */
    private function recentOutgoings(User $user): Collection
    {
        return Transaction::query()
            ->where('transactions.user_id', $user->id)
            ->where('transactions.amount', '<', 0)
            ->where('transactions.transaction_date', '>=', now()->startOfMonth()->subMonths(self::MONTHS_READ - 1))
            ->whereIn('accounts.type', $this->spendingTypes())
            ->countingTowardsTotals()
            ->with('account')
            ->get();
    }

    /**
     * Account types a movement can be spending from. A pension, a broker or a
     * mortgage moves as a balance, and the rows behind it are adjustments — they
     * would read as a €2,000 spending month nobody actually had.
     *
     * @return list<AccountType>
     */
    private function spendingTypes(): array
    {
        return array_values(array_filter(
            AccountType::cases(),
            fn (AccountType $type): bool => $type->hasTransactionLedger(),
        ));
    }

    /**
     * Who the user paid most that month, largest first.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return list<array{name: string, amount: int}>
     */
    private function topMerchants(Collection $transactions, string $currency): array
    {
        return $transactions
            ->groupBy(fn (Transaction $transaction): string => $this->merchantOf($transaction) ?? '')
            // Rows nobody can name: no counterparty, and a description we hold
            // only as ciphertext. They still count towards the total.
            ->forget('')
            ->map(fn (Collection $rows): int => -$this->sumConvertedAmounts($rows, $currency))
            ->sortDesc()
            ->take(self::TOP_MERCHANTS)
            ->map(fn (int $amount, string $name): array => ['name' => $name, 'amount' => $amount])
            ->values()
            ->all();
    }

    /**
     * Who a movement was paid to, in the words the row itself carries.
     *
     * A bank names the counterparty; a file usually does not, so the description
     * stands in — the same text step 8 writes its rules from, and skipped for
     * the same reason when it is encrypted at rest.
     */
    private function merchantOf(Transaction $transaction): ?string
    {
        if ($transaction->creditor_name) {
            return $transaction->creditor_name;
        }

        return $transaction->description_iv === null ? $transaction->description : null;
    }

    /**
     * How many distinct merchants step 8 has to turn into categories, which is
     * what this screen's own call to action promises.
     *
     * Counted over everything still uncategorized rather than over the revealed
     * month alone, because that is the pile the next step actually works on:
     * the filter and the merchant/description fallback both mirror
     * {@see RuleSuggestionAggregator::groupsFor()}.
     */
    private function merchantCount(User $user): int
    {
        return (int) Transaction::query()
            ->where('user_id', $user->id)
            ->pendingAiCategorization()
            ->selectRaw("count(distinct coalesce(nullif(creditor_name, ''), description)) as merchants")
            ->value('merchants');
    }

    /**
     * Merchants that charged the same amount in each of the last three months.
     *
     * Grouped by merchant and exact amount, which is what "the same" means to
     * someone reading a statement. It comes out of the rows themselves, which is
     * the only reason the screen can claim it before anything is categorized.
     *
     * @param  Collection<string, Collection<int, Transaction>>  $byMonth
     */
    private function recurringMerchants(Collection $byMonth, string $month): int
    {
        $window = collect(range(0, self::RECURRING_MONTHS - 1))
            ->map(fn (int $back): string => Carbon::parse($month.'-01')->subMonths($back)->format('Y-m'));

        return $window
            ->flatMap(fn (string $month): Collection => $byMonth->get($month, collect()))
            ->groupBy(fn (Transaction $transaction): string => $this->merchantOf($transaction).'|'.$transaction->amount)
            ->filter(fn (Collection $charges): bool => $charges
                ->map(fn (Transaction $charge): string => $charge->transaction_date->format('Y-m'))
                ->unique()
                ->count() === self::RECURRING_MONTHS)
            ->keys()
            // One merchant billing two subscriptions is one merchant, and the
            // sentence on the screen counts merchants.
            ->map(fn (string $key): string => explode('|', $key)[0])
            ->filter()
            ->unique()
            ->count();
    }

    /**
     * The other half of the flow: someone who added a pension, a mortgage or a
     * broker and no current account has nothing to be told about their spending.
     * They are shown what they are worth, and offered the half they are missing.
     *
     * @return array<string, mixed>
     */
    private function assets(User $user): array
    {
        $accounts = $user->accounts()->get();
        $today = now();
        $lookup = BalanceLookup::forAccounts($accounts->pluck('id'), $today, $today);
        $excluded = $this->netWorth->excludedTypesFor($user);
        $currency = $user->currency_code;

        return [
            'variant' => 'assets',
            'currency_code' => $currency,
            'net_worth' => $this->netWorth->at($accounts, $lookup, $today, $currency, $excluded),
            // Only what the total is made of: a credit card listed under a
            // figure it was left out of is a figure that does not add up.
            'accounts' => $accounts
                ->filter(fn (Account $account): bool => $this->netWorth->countsOn($account, $excluded, $today))
                ->map(fn (Account $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'connected' => $account->banking_connection_id !== null,
                    'balance' => $this->netWorth->contributionOf($account, $lookup, $today, $currency),
                ])
                ->sortByDesc('balance')
                ->values()
                ->all(),
        ];
    }
}
