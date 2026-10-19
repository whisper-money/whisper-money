<?php

namespace App\Services\CreditCards;

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Concerns\ConvertsTransactionCurrency;
use App\Services\ExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/**
 * Estimates what a credit card will charge next, and when, from its statement
 * dates and its own ledger. Computed on every read and never written back, so
 * nothing it says can be counted twice anywhere else.
 */
class CreditCardStatementService
{
    use ConvertsTransactionCurrency;

    public function __construct(private ExchangeRateService $exchangeRateService) {}

    /**
     * The credit usage of every credit card among $accounts, keyed by account
     * id, for the lists that show a card's usage instead of its balance.
     * One query per card, with their details loaded together.
     *
     * @param  EloquentCollection<int, Account>  $accounts
     * @return array<string, array{limit: int|null, used: int, available: int|null, period_from: string, period_to: string, daily: list<array{date: string, used: int}>}>
     */
    public function usageByAccount(EloquentCollection $accounts, User $user): array
    {
        $creditCards = $accounts
            ->filter(fn (Account $account): bool => $account->type === AccountType::CreditCard)
            ->loadMissing('creditCardDetail');
        $today = $this->todayFor($user);

        return $creditCards
            ->mapWithKeys(fn (Account $account): array => [
                $account->id => $this->figuresOn($account, $today)['credit_card_usage'],
            ])
            ->all();
    }

    /**
     * The credit card fields an account row carries: the detail, the
     * statement estimate and the credit usage on a credit card, nothing at
     * all on any other account.
     *
     * @return array<string, mixed>
     */
    public function presentFor(Account $account, User $user): array
    {
        if ($account->type !== AccountType::CreditCard) {
            return [];
        }

        $today = $this->todayFor($user);
        $detail = $account->creditCardDetail;

        return [
            'credit_card_detail' => $detail === null ? null : [
                'statement_closing_date' => $detail->statement_closing_date?->toDateString(),
                'payment_due_date' => $detail->payment_due_date?->toDateString(),
                'statement_frequency' => $detail->statement_frequency->value,
                'credit_limit' => $detail->credit_limit,
            ],
            ...$this->figuresOn($account, $today),
        ];
    }

    /**
     * What a credit card's ledger says as of $today: the estimate its
     * statement dates give (null while it has none) and how much of the card
     * is in use. Both come from one query (two when a payment settles a
     * statement with no purchases, see hasRecordedPurchasesBy). Reads the
     * `creditCardDetail` relation, so eager-load it when presenting several
     * cards.
     *
     * Usage covers what is still to be charged: from the statement paid next
     * (a closed one not charged yet, or else the open cycle) through the open
     * cycle. Without statement dates there is no cycle to go by, so it covers
     * the calendar month. Rows booked after today are left out of it, since
     * they have not drawn on the limit yet. `available` goes negative over
     * the limit, and both are null while no limit is set. Repayments made on
     * a statement inside that window bring usage down from the day they were
     * booked (or the window's first day, when booked before it), floored at
     * zero. Those made on the statement before it pay purchases outside the
     * window, so they are left out. Without statement dates nothing says what
     * a repayment pays, so none is subtracted.
     *
     * The estimate also lists the repayments booked in that window up to
     * today (from the day the statement before is charged, when that is the
     * day it closes), each with the statement it pays, says how much of the
     * next payment is already paid, and sums up the statement before it,
     * which is the one those repayments usually settle.
     *
     * @return array{
     *     credit_card_statement: array{
     *         next_payment: array{period_from: string, closing_date: string, due_date: string, amount: int, is_final: bool, paid: int},
     *         current_cycle: array{period_from: string, closing_date: string, due_date: string, amount: int},
     *         payments: list<array{transaction_id: string, date: string, amount: int, period_from: string, closing_date: string, due_date: string}>,
     *         previous_statement: array{period_from: string, closing_date: string, due_date: string, purchases: int, paid: int, pending: int, has_recorded_purchases: bool},
     *     }|null,
     *     credit_card_usage: array{
     *         limit: int|null,
     *         used: int,
     *         available: int|null,
     *         period_from: string,
     *         period_to: string,
     *         daily: list<array{date: string, used: int}>,
     *     },
     * }
     */
    public function figuresOn(Account $account, CarbonImmutable $today): array
    {
        $schedule = StatementSchedule::fromDetail($account->creditCardDetail);
        $nextPayment = $schedule?->nextPaymentOn($today);
        $currentCycle = $schedule?->openCycleOn($today);
        $previousStatement = $nextPayment === null ? null : $schedule->closedCycleBefore($nextPayment['cycle']->periodFrom);

        $from = $nextPayment['cycle']->periodFrom ?? $today->startOfMonth();
        $to = $currentCycle->closingDate ?? $today->endOfMonth()->startOfDay();
        $paymentsFrom = $previousStatement === null ? $from : $from->min($previousStatement->dueDate);

        [$repayments, $transactions] = $this->ledgerRows($account, $previousStatement->periodFrom ?? $from, $to)
            ->partition(fn (Transaction $transaction): bool => self::isRepayment($transaction));
        $payments = $schedule === null ? collect() : $this->paymentsBetween($repayments, $schedule, $paymentsFrom, $today, $account->currency_code);

        return [
            'credit_card_statement' => $nextPayment === null || $currentCycle === null ? null : [
                'next_payment' => [
                    ...$nextPayment['cycle']->toArray(),
                    'amount' => $this->amountToPay($transactions, $nextPayment['cycle'], $account->currency_code),
                    'is_final' => $nextPayment['is_final'],
                    'paid' => self::paidOn($payments, $nextPayment['cycle']),
                ],
                'current_cycle' => [
                    ...$currentCycle->toArray(),
                    'amount' => $this->amountToPay($transactions, $currentCycle, $account->currency_code),
                ],
                'payments' => $payments->all(),
                'previous_statement' => $this->previousStatementSummary($account, $previousStatement, $transactions, $payments),
            ],
            'credit_card_usage' => $this->usage($transactions, self::paymentsInWindow($payments, $from), $from, $to, $today, $account),
        ];
    }

    /**
     * The card's rows booked between two dates, read on the date the bank
     * booked them: users move `transaction_date` to tidy their cashflow,
     * while `source_date` keeps the bank's. Each row carries its category
     * type, so repayments can be told apart without another query.
     *
     * @return Collection<int, Transaction>
     */
    private function ledgerRows(Account $account, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $transactions = $this->ledgerQuery($account)
            ->whereRaw('COALESCE(transactions.source_date, transactions.transaction_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->each(fn (Transaction $transaction) => $transaction->setRelation('account', $account));

        $this->preloadExchangeRates($transactions, $account->currency_code);

        return $transactions;
    }

    /**
     * @return HasMany<Transaction, Account>
     */
    private function ledgerQuery(Account $account): HasMany
    {
        return $account->transactions()
            ->leftJoin('categories', fn (JoinClause $join) => $join
                ->on('categories.id', '=', 'transactions.category_id')
                ->whereNull('categories.deleted_at'))
            ->select('transactions.*', 'categories.type as category_type');
    }

    /**
     * Whether a row repays the card instead of ending up on a statement: rows
     * in a transfer category, and uncategorized inflows, which on a card are
     * almost always the repayment itself.
     */
    private static function isRepayment(Transaction $transaction): bool
    {
        return $transaction->getAttribute('category_type') === CategoryType::Transfer->value
            || ($transaction->category_id === null && $transaction->amount > 0);
    }

    /**
     * The repayments booked from $from through $today, oldest first, each with
     * the statement it pays (see StatementSchedule::statementPaidOn).
     *
     * @param  Collection<int, Transaction>  $repayments
     * @return Collection<int, array{transaction_id: string, date: string, amount: int, period_from: string, closing_date: string, due_date: string}>
     */
    private function paymentsBetween(Collection $repayments, StatementSchedule $schedule, CarbonImmutable $from, CarbonImmutable $today, string $currency): Collection
    {
        return $repayments
            ->filter(fn (Transaction $transaction): bool => $transaction->amount > 0
                && self::bookedOn($transaction)->betweenIncluded($from, $today))
            ->sortBy(fn (Transaction $transaction): string => self::bookedOn($transaction)->toDateString())
            ->map(fn (Transaction $transaction): array => [
                'transaction_id' => $transaction->id,
                'date' => self::bookedOn($transaction)->toDateString(),
                'amount' => $this->convertFullTransactionAmount($transaction, $currency),
                ...$schedule->statementPaidOn(self::bookedOn($transaction))->toArray(),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array{closing_date: string, amount: int}>  $payments
     */
    private static function paidOn(Collection $payments, StatementCycle $statement): int
    {
        return $payments->where('closing_date', $statement->closingDate->toDateString())->sum('amount');
    }

    /**
     * The payments made on a statement from $from on, which pay purchases
     * inside the usage window, each dated no earlier than $from.
     *
     * @param  Collection<int, array{date: string, amount: int, period_from: string}>  $payments
     * @return Collection<int, array{date: string, amount: int, period_from: string}>
     */
    private static function paymentsInWindow(Collection $payments, CarbonImmutable $from): Collection
    {
        $windowStart = $from->toDateString();

        return $payments
            ->filter(fn (array $payment): bool => $payment['period_from'] >= $windowStart)
            ->map(fn (array $payment): array => [...$payment, 'date' => max($payment['date'], $windowStart)]);
    }

    /**
     * What the statement before the one paid next adds up to, has been paid
     * and is still pending.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, array{closing_date: string, amount: int}>  $payments
     * @return array{period_from: string, closing_date: string, due_date: string, purchases: int, paid: int, pending: int, has_recorded_purchases: bool}
     */
    private function previousStatementSummary(Account $account, StatementCycle $statement, Collection $transactions, Collection $payments): array
    {
        $purchases = $this->amountToPay($transactions, $statement, $account->currency_code);
        $paid = self::paidOn($payments, $statement);

        return [
            ...$statement->toArray(),
            'purchases' => $purchases,
            'paid' => $paid,
            'pending' => max(0, $purchases - $paid),
            'has_recorded_purchases' => $this->hasRecordedPurchasesBy($account, $statement, $transactions, $paid),
        ];
    }

    /**
     * Whether the card has any purchase booked by the end of $statement. When
     * it has none, the app holds no data for that statement, so what was paid
     * on it cannot be checked against what it charged.
     *
     * Looks back in SQL only when the statement itself is empty and something
     * was paid on it, so a card listed without such a payment still costs one
     * query. An empty statement nobody paid has nothing to explain. The rows
     * come in chunks until the first purchase, so isRepayment stays the one
     * rule.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    private function hasRecordedPurchasesBy(Account $account, StatementCycle $statement, Collection $transactions, int $paid): bool
    {
        if ($transactions->contains(fn (Transaction $transaction): bool => self::bookedOn($transaction)->lessThanOrEqualTo($statement->closingDate))) {
            return true;
        }

        if ($paid === 0) {
            return false;
        }

        return $this->ledgerQuery($account)
            ->whereRaw('COALESCE(transactions.source_date, transactions.transaction_date) < ?', [$statement->periodFrom->toDateString()])
            ->orderBy('transactions.id')
            ->lazy(100)
            ->contains(fn (Transaction $transaction): bool => ! self::isRepayment($transaction));
    }

    /**
     * What the cycle adds up to as an amount to pay: purchases are negative on
     * the ledger, so the signed sum is flipped, and refunds bring it down. The
     * whole amount counts, whatever share of the card the user owns, because
     * the bank charges all of it.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    private function amountToPay(Collection $transactions, StatementCycle $cycle, string $currency): int
    {
        return -$transactions
            ->filter(fn (Transaction $transaction): bool => $cycle->contains(self::bookedOn($transaction)))
            ->sum(fn (Transaction $transaction): int => $this->convertFullTransactionAmount($transaction, $currency));
    }

    /**
     * Where the card stands against its limit, with the running total of
     * what it has drawn day by day from $from through $today, less what
     * $payments repaid. The total is floored at zero every day, so a payment
     * larger than what the card recorded (purchases missing from it, fees)
     * never hides the purchases that come after it.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, array{date: string, amount: int}>  $payments
     * @return array{limit: int|null, used: int, available: int|null, period_from: string, period_to: string, daily: list<array{date: string, used: int}>}
     */
    private function usage(Collection $transactions, Collection $payments, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today, Account $account): array
    {
        $drawnByDay = $transactions
            ->groupBy(fn (Transaction $transaction): string => self::bookedOn($transaction)->toDateString())
            ->map(fn (Collection $day): int => -$day->sum(fn (Transaction $transaction): int => $this->convertFullTransactionAmount($transaction, $account->currency_code)));

        $repaidByDay = $payments
            ->groupBy('date')
            ->map(fn (Collection $day): int => $day->sum('amount'));

        $used = 0;
        $daily = [];

        for ($day = $from; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            $used = max(0, $used + $drawnByDay->get($day->toDateString(), 0) - $repaidByDay->get($day->toDateString(), 0));
            $daily[] = ['date' => $day->toDateString(), 'used' => $used];
        }

        $limit = $account->creditCardDetail?->credit_limit;

        return [
            'limit' => $limit,
            'used' => $used,
            'available' => $limit === null ? null : $limit - $used,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'daily' => $daily,
        ];
    }

    private function todayFor(User $user): CarbonImmutable
    {
        return CarbonImmutable::parse(now($user->timezone ?? config('app.timezone'))->toDateString());
    }

    private static function bookedOn(Transaction $transaction): CarbonImmutable
    {
        return CarbonImmutable::parse(($transaction->source_date ?? $transaction->transaction_date)->toDateString());
    }
}
