<?php

namespace App\Services\CreditCards;

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Features\CreditCardStatements;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Concerns\ConvertsTransactionCurrency;
use App\Services\ExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * Estimates what a credit card will charge next, and when, from its statement
 * dates and its own ledger. Computed on every read and never written back, so
 * nothing it says can be counted twice anywhere else.
 */
class CreditCardStatementService
{
    use ConvertsTransactionCurrency;

    public function __construct(private ExchangeRateService $exchangeRateService) {}

    public function isAvailableTo(User $user): bool
    {
        return Feature::for($user)->active(CreditCardStatements::class);
    }

    /**
     * The credit card fields an account row carries for this user: the
     * detail, the statement estimate and the credit usage on a credit card
     * when the feature is on, nothing at all otherwise.
     *
     * @return array<string, mixed>
     */
    public function presentFor(Account $account, User $user): array
    {
        if ($account->type !== AccountType::CreditCard || ! $this->isAvailableTo($user)) {
            return [];
        }

        $today = CarbonImmutable::parse(now($user->timezone ?? config('app.timezone'))->toDateString());
        $detail = $account->creditCardDetail;

        return [
            'credit_card_detail' => $detail === null ? null : [
                'statement_closing_date' => $detail->statement_closing_date?->toDateString(),
                'payment_due_date' => $detail->payment_due_date?->toDateString(),
                'credit_limit' => $detail->credit_limit,
            ],
            ...$this->figuresOn($account, $today),
        ];
    }

    /**
     * What a credit card's ledger says as of $today: the estimate its
     * statement dates give (null while it has none) and how much of the card
     * is in use. Both come from one query. Reads the `creditCardDetail`
     * relation, so eager-load it when presenting several cards.
     *
     * Usage covers what is still to be charged: from the statement paid next
     * (a closed one not charged yet, or else the open cycle) through the open
     * cycle. Without statement dates there is no cycle to go by, so it covers
     * the calendar month. Rows booked after today are left out of it, since
     * they have not drawn on the limit yet. `available` goes negative over
     * the limit, and both are null while no limit is set.
     *
     * @return array{
     *     credit_card_statement: array{
     *         next_payment: array{period_from: string, closing_date: string, due_date: string, amount: int, is_final: bool},
     *         current_cycle: array{period_from: string, closing_date: string, due_date: string, amount: int},
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

        $from = $nextPayment['cycle']->periodFrom ?? $today->startOfMonth();
        $to = $currentCycle->closingDate ?? $today->endOfMonth()->startOfDay();

        $transactions = $this->chargeableTransactions($account, $from, $to);

        return [
            'credit_card_statement' => $nextPayment === null || $currentCycle === null ? null : [
                'next_payment' => [
                    ...$nextPayment['cycle']->toArray(),
                    'amount' => $this->amountToPay($transactions, $nextPayment['cycle'], $account->currency_code),
                    'is_final' => $nextPayment['is_final'],
                ],
                'current_cycle' => [
                    ...$currentCycle->toArray(),
                    'amount' => $this->amountToPay($transactions, $currentCycle, $account->currency_code),
                ],
            ],
            'credit_card_usage' => $this->usage($transactions, $from, $to, $today, $account),
        ];
    }

    /**
     * The card's rows that end up on a statement between two dates, read on the
     * date the bank booked them: users move `transaction_date` to tidy their
     * cashflow, while `source_date` keeps the bank's.
     *
     * Repayments are left out: rows in a transfer category, and uncategorized
     * inflows, which on a card are almost always the repayment itself. Both
     * are filtered in SQL so a card costs one query, however many are listed.
     *
     * @return Collection<int, Transaction>
     */
    private function chargeableTransactions(Account $account, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $transactions = $account->transactions()
            ->leftJoin('categories', fn (JoinClause $join) => $join
                ->on('categories.id', '=', 'transactions.category_id')
                ->whereNull('categories.deleted_at'))
            ->whereRaw('COALESCE(transactions.source_date, transactions.transaction_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->where(fn (Builder $query) => $query
                ->whereNull('categories.type')
                ->orWhere('categories.type', '!=', CategoryType::Transfer->value))
            ->where(fn (Builder $query) => $query
                ->whereNotNull('transactions.category_id')
                ->orWhere('transactions.amount', '<=', 0))
            ->select('transactions.*')
            ->get()
            ->each(fn (Transaction $transaction) => $transaction->setRelation('account', $account));

        $this->preloadExchangeRates($transactions, $account->currency_code);

        return $transactions;
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
     * what it has drawn day by day from $from through $today.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array{limit: int|null, used: int, available: int|null, period_from: string, period_to: string, daily: list<array{date: string, used: int}>}
     */
    private function usage(Collection $transactions, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today, Account $account): array
    {
        $drawnByDay = $transactions
            ->groupBy(fn (Transaction $transaction): string => self::bookedOn($transaction)->toDateString())
            ->map(fn (Collection $day): int => -$day->sum(fn (Transaction $transaction): int => $this->convertFullTransactionAmount($transaction, $account->currency_code)));

        $used = 0;
        $daily = [];

        for ($day = $from; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            $used += $drawnByDay->get($day->toDateString(), 0);
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

    private static function bookedOn(Transaction $transaction): CarbonImmutable
    {
        return CarbonImmutable::parse(($transaction->source_date ?? $transaction->transaction_date)->toDateString());
    }
}
